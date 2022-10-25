<?php namespace Model\Router;

use Model\Core\Module;

class Router extends Module
{
	/** @var int|bool */
	public $pageId = null;
	/** @var array */
	private $rules = [];
	/** @var array */
	private $cache = [];
	/** @var string */
	private $accetableCharacters = 'a-zа-я0-9_\p{Han}-';

	public function init(array $options)
	{
		$this->options = array_merge(array(
			'charLengthIndexed' => array(),
		), $options);

		if (file_exists(INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Router' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'rules.php')) {
			require(INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Router' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'rules.php');
			$this->rules = $rules;
		}
	}

	/**
	 * Returns the appropriate controller, given the request
	 * It also resolves elements and categories in the request, if present
	 * $rule is the index of the rule to be used
	 *
	 * @param array $request
	 * @param string $rule
	 * @return array|null
	 */
	public function getController(array $request, string $rule): ?array
	{
		if (isset($this->rules[$rule])) {
			$options = $this->rules[$rule]['options'];

			// Check categoria
			$c = 0;
			$lastCat = false;
			$lastField = false;
			foreach ($request as $i => $r) {
				if (!isset($this->rules[$rule]['rule'][$i]))
					continue;
				$sub_rule = $this->rules[$rule]['rule'][$i];
				if (preg_match('/\[p:[a-z0-9_-]+\]/i', $sub_rule)) {
					$parent_options = $options['parent'][$c++];

					if (strpos($sub_rule, '[p:' . $parent_options['id'] . ']') !== false) {
						$id = $this->resolveId($r, $sub_rule, '[p:' . $parent_options['id'] . ']');
						if ($id and is_numeric($id)) {
							$lastCat = (int)$id;
							$lastField = $parent_options['field'];
							continue;
						}
					}

					$lastCat = $this->resolveFromDb($r, $sub_rule, [
						'id' => $parent_options['id'],
						'table' => $parent_options['table'],
						'query-parent' => $lastCat !== false ? [$lastField, $lastCat] : [],
						'if-null' => $options['if-null'],
					]);
					if ($lastCat === false)
						return null;

					$lastField = $parent_options['field'];
				}
			}

			// I look for the element id, if present
			$found_id = false;
			foreach ($request as $i => $r) {
				if (!isset($this->rules[$rule]['rule'][$i]))
					continue;
				$sub_rule = $this->rules[$rule]['rule'][$i];
				if (strpos($sub_rule, '[el:' . $options['id'] . ']') !== false) {
					$id = $this->resolveId($r, $sub_rule, '[el:' . $options['id'] . ']');
					if ($id and is_numeric($id)) {
						// The id is in the request, I check if it really exists
						$where[$options['id']] = $id;
						if ($lastCat !== false) {
							$where[$lastField] = $lastCat;
						}
						$check = $this->model->_Db->select($options['table'], $where);
						if (!$check)
							return null;

						$found_id = $id;
						break;
					}
				}
			}

			// No element id found, I proceed with searching by name
			if ($found_id === false) {
				foreach ($request as $i => $r) {
					if (!isset($this->rules[$rule]['rule'][$i]))
						continue;
					$sub_rule = $this->rules[$rule]['rule'][$i];
					if (preg_match('/\[el:[a-z0-9_-]+\]/i', $sub_rule)) {
						$check = $this->resolveFromDb($r, $sub_rule, [
							'id' => $options['id'],
							'table' => $options['table'],
							'query-parent' => $lastCat !== false ? [$lastField, $lastCat] : [],
							'if-null' => $options['if-null'],
						]);

						if ($check !== false) {
							$found_id = $check;
							break;
						}
					}
				}
			}

			if ($found_id !== false) {
				$this->pageId = $found_id;
				if ($options['element']) {
					$this->model->_ORM->loadMainElement($options['element'], $found_id);
				}
			}

			return [
				'controller' => $this->rules[$rule]['controller'],
			];
		} else {
			return null;
		}
	}

	/**
	 * Extracts the numeric id from a request
	 *
	 * @param string $request
	 * @param string $rule
	 * @param string $pattern
	 * @return int
	 */
	private function resolveId(string $request, string $rule, string $pattern): int
	{
		$regex = str_replace($pattern, '([0-9]+)', $rule);
		$regex = preg_replace('/\[(el|p):[a-z0-9_-]+\]/i', '.*', $regex);
		$regex = str_replace('[*]', '[^?/]*', $regex); // Backward compatibility
		$id = preg_replace('/^' . $regex . '$/i', '$1', $request);
		return $id;
	}

	/**
	 * Reads required data from database
	 *
	 * @param string $req
	 * @param string $rule
	 * @param array $options
	 * @return bool|null|string
	 */
	private function resolveFromDb(string $req, string $rule, array $options)
	{
		if ($req == $options['if-null']) {
			return null;
		} else {
			$gruppi = $this->makeWordsGroups($rule, $req);
			if (count($gruppi) == 0)
				return true;

			$campi_coinvolti = array();
			$qry_ar = array();
			foreach ($gruppi as $g) {
				if (count($g['words']) < count($g['fields']))
					return false;

				$qry_gr = array();
				$combinazioni = $this->possibleCombinations($g['words'], $g['fields']);
				foreach ($combinazioni as $comb) {
					$qry_comb = array();
					foreach ($comb as $k => $v) {
						if (!array_key_exists($k, $campi_coinvolti))
							$campi_coinvolti[$k] = 'CHAR_LENGTH(' . $k . ')';
						$qry_comb[] = [$k, 'LIKE', '%' . $v . '%'];
					}
					$qry_gr[] = ['sub' => $qry_comb, 'operator' => 'and'];
				}
				$qry_ar[] = ['sub' => $qry_gr, 'operator' => 'or'];
			}

			$order_by = in_array($options['table'], $this->options['charLengthIndexed']) ? 'zk_char_length' : '(' . implode('+', $campi_coinvolti) . ')';
			if ($options['query-parent'])
				$qry_ar[] = $options['query-parent'];
			return $this->model->_Db->select($options['table'], $qry_ar, ['field' => $options['id'], 'order_by' => $order_by]);
		}
	}

	/**
	 * Creates groups of words to search for, in the database
	 * Each group is made of a list of words and the fields where to search for them
	 *
	 * For example if a rule is like this [el:name]-[el:surname]-age-[el:age]
	 * For a request like this: diego-de-la-vega-age-17
	 * There will be two groups:
	 * * A first one with the words [diego, de, la, vega] and the fields [name, surname]
	 * * A second one with the word [17] and the field [age]
	 *
	 * @param string $paradigma
	 * @param string $req
	 * @return array
	 */
	private function makeWordsGroups(string $paradigma, string $req): array
	{
		$num_gruppi = preg_match_all('/\[(el|p):[a-z0-9_-]+\](-\[(el|p):[a-z0-9_-]+\])*/i', $paradigma, $par_gruppi);
		if ($num_gruppi == 0) return array();

		$regex = $paradigma;
		foreach ($par_gruppi[0] as $g)
			$regex = str_replace($g, '([' . $this->accetableCharacters . ']*)', $regex);

		preg_match_all('/' . $regex . '/iu', $req, $valori, PREG_SET_ORDER);
		$valori = $valori[0];
		array_shift($valori);

		$gruppi = array();
		foreach ($par_gruppi[0] as $cg => $g) {
			$campi = explode('-', preg_replace('/\[(el|p):([a-z0-9_-]+)\]/i', '$2', $g));
			$gruppi[] = [
				'fields' => $campi,
				'words' => explode('-', $valori[$cg]),
			];
		}

		return $gruppi;
	}

	/**
	 * For each of the groups made with the previous method, I create all possible combinations to look for
	 * For example in the previous example of words [diego, de, la, vega] and fields [name, surname]
	 * The possible combinations will look something like this:
	 *  [name=>diego, surname=>de la vega]
	 *  [name=>diego de, surname=>la vega]
	 *  [name=>diego de la, surname=>vega]
	 *
	 * @param array $words
	 * @param array $fields
	 * @return array
	 */
	private function possibleCombinations(array $words, array $fields): array
	{
		$n = count($fields);
		if ($n == 1) { // Shortcut
			return [
				[
					$fields[0] => implode('%', $words),
				],
			];
		}
		if ($n == count($words)) { // Shortcut
			$combinazione = [];
			foreach ($words as $cp => $p)
				$combinazione[$fields[$cp]] = $p;
			return [$combinazione];
		}

		$prototipo_combinazioni = $this->createCombination(count($words), $n);
		$combinazioni = array();
		foreach ($prototipo_combinazioni as $prot) {
			$parole_comb = $words;
			$combinazione = array();
			foreach ($prot as $cp => $n_parole) {
				$el_combinazione = array();
				for ($c = 1; $c <= $n_parole; $c++)
					$el_combinazione[] = array_shift($parole_comb);
				$combinazione[$fields[$cp]] = implode('%', $el_combinazione);
			}
			$combinazioni[] = $combinazione;
		}
		return $combinazioni;
	}

	/**
	 * Extension of the previous method.
	 * In order to make all the combinations, I think of the fields as "containers" where to put the words.
	 * So, if we have, say, 4 words and 3 fields (-> 3 containers), I will start putting 4 words in the first one, and 0 in the second one
	 * In the next loop I will put 3 in the first one, and 1 in the second, and so on
	 * And I keep track of all the combinations with 0 empty containers, so in the end I will have a situation like this: 2-1-1, 1-2-1, 1-1-2
	 *
	 * It's important to notice, I'm not using the actual words and fields in this method, I am just creating the "patterns" to use for the "possibleCombinations" method, hence I give only the total number of words and field as arguments
	 *
	 * @param int $words
	 * @param int $fields
	 * @return array
	 */
	private function createCombination(int $words, int $fields): array
	{
		$prototipo_combinazioni = [];
		for ($c = $words; $c >= 1; $c--) {
			$temp_combinazione = [$c];
			$rimanenti = $words - $c;
			if ($fields > 1) {
				if ($rimanenti == 0) continue;
				$combinazioni_successive = $this->createCombination($rimanenti, $fields - 1);
				if (count($combinazioni_successive) == 0)
					continue;
				foreach ($combinazioni_successive as $comb)
					$prototipo_combinazioni[] = array_merge($temp_combinazione, $comb);
			} elseif ($rimanenti == 0)
				$prototipo_combinazioni[] = $temp_combinazione;
		}
		return $prototipo_combinazioni;
	}

	/**
	 * Builds a request url based on the given parameters
	 *
	 * @param string|null $controller
	 * @param null|string $id
	 * @param array $tags
	 * @param array $opt
	 * @return bool|string
	 */
	public function getUrl(?string $controller = null, ?string $id = null, array $tags = [], array $opt = []): ?string
	{
		if ($controller === null)
			return null;

		$this->trigger('gettingUrl', [
			'controller' => $controller,
			'id' => $id,
			'tags' => $tags,
			'opt' => $opt,
		]);

		$opt = array_merge([
			'fields' => [],
			'idx' => null,
		], $opt);

		if (class_exists('\\Model\\Multilang\\Ml') and !isset($tags['lang']))
			$tags['lang'] = \Model\Multilang\Ml::getLang();

		$rules = $this->getRulesFor($controller, $tags);

		$url = null;
		foreach ($rules as $rIdx => $r) {
			if ($opt['idx'] !== null and $rIdx !== $opt['idx'])
				continue;
			$attempt = $this->getUrlFromRule($controller, $id, $tags, $opt, $r);
			if ($attempt !== null) {
				$url = $attempt;
				break;
			}
		}

		if ($url === null)
			return null;

		return implode('/', $url);
	}

	/**
	 * @param string|null $controller
	 * @param array $tags
	 * @return array
	 */
	public function getRulesFor(?string $controller = null, array $tags = []): array
	{
		$rules = [];
		foreach ($this->rules as $r) {
			if ($r['controller'] == $controller) {
				foreach ($tags as $k => $v) {
					if (isset($r['options']['tags'][$k]) and $r['options']['tags'][$k] != $v)
						continue 2;
				}
				$rules[] = $r;
			}
		}

		return $rules;
	}

	/**
	 * Attempts to build a request url given a specific rule (returns false in case of failure)
	 *
	 * @param string $controller
	 * @param null|string $id
	 * @param array $tags
	 * @param array $opt
	 * @param array $rule
	 * @return array|null
	 */
	private function getUrlFromRule(string $controller, ?string $id, array $tags, array $opt, array $rule): ?array
	{
		$ordine = []; // Mi creo l'ordine (si procede dal "prodotto" salendo per le categorie, se presenti. Quindi nel primo ciclo cerco il prodotto, nel secondo giro le categorie, nel terzo tutto il resto
		foreach ($rule['rule'] as $cr => $r) {
			if (strpos($r, '[el:') !== false) {
				$ordine[] = $cr;
			}
		}
		$rule['rule'] = array_reverse($rule['rule']);
		foreach ($rule['rule'] as $cr => $r) {
			if (strpos($r, '[p:') !== false) {
				if (!in_array($cr, $ordine))
					$ordine[] = $cr;
			}
		}
		$rule['rule'] = array_reverse($rule['rule']);
		foreach ($rule['rule'] as $cr => $r) {
			if (!in_array($cr, $ordine))
				$ordine[] = $cr;
		}

		$return = [];
		$cats = [];
		$c_cat = 0;
		foreach ($ordine as $cr) { // Controllo corrispondenza url e ricerca id
			$paradigma = $rule['rule'][$cr];

			if (strpos($paradigma, '[p:') !== false) {
				if (strpos($paradigma, '[el:') === false) { // Se non c'è nessun elemento [el], è una vera categoria, altrimenti è solo un richiamo a un campo della categoria parent di quest'elemento
					if (array_key_exists($c_cat, $cats) and $cats[$c_cat] === null) {
						if (isset($rule['options']['if-null']))
							$paradigma = $rule['options']['if-null'];
						else
							$paradigma = '';
					} else {
						if (isset($cats[$c_cat])) {
							$row = $this->getFromDb($rule['options']['parent'][$c_cat]['table'], $cats[$c_cat], $rule['options']['parent'][$c_cat]['id'], $tags['lang'] ?? null);
							if ($row === null)
								return null;
							if (isset($rule['options']['parent'][$c_cat + 1]))
								$cats[$c_cat + 1] = $row[$rule['options']['parent'][$c_cat]['field']];

							foreach ($row as $k => $v) {
								if (!isset($rule['options']['dontEncode']))
									$v = rewriteUrlWords([$v], $rule['options']['lowercase']);
								$paradigma = str_replace('[p:' . $k . ']', $v, $paradigma);
								if (isset($cat_replacing))
									$return[$cat_replacing] = str_replace('[p:' . $k . ']', $v, $return[$cat_replacing]);
							}
						}

						if (isset($cat_replacing))
							unset($cat_replacing);
					}

					$c_cat++;
				} else {
					$cat_replacing = $cr;
				}
			}

			if (strpos($paradigma, '[el:') !== false) {
				$node_parent = false;

				if ($id === null) { // Se non ho l'id, devo cercarlo
					if ($controller == $this->model->controllerName and $this->pageId !== null) // Se i parametri combaciano, posso usare l'id che ho in cache, se ne ho uno
						$id = $this->pageId;
					if ($id === null)
						return null;
				}

				if (strpos($paradigma, '[el:' . $rule['options']['id'] . ']') !== false)
					$paradigma = str_replace('[el:' . $rule['options']['id'] . ']', $id, $paradigma);


				preg_match_all('/\[el:([a-z0-9_-]+)\]/i', $paradigma, $matches); // Se mi manca qualche campo e non ce l'ho passato fra i parametri, devo prenderlo da DB
				$prendi = $matches[1];
				foreach ($prendi as $k => $v) {
					if (array_key_exists($v, $opt['fields']))
						unset($prendi[$k]);
				}

				if (count($rule['options']['parent']) > 0) { // Se mi serve l'id del parent, vedo se ce l'ho fra i campi passati, altrimenti devo prenderlo dal db
					if (array_key_exists($rule['options']['parent'][0]['field'], $opt['fields']))
						$node_parent = $opt['fields'][$rule['options']['parent'][0]['field']];
					else
						$prendi[] = $rule['options']['parent'][0]['field'];
				}

				if (!empty($prendi)) {
					$row = $this->getFromDb($rule['options']['table'], $id, $rule['options']['id'], $tags['lang'] ?? null); // Prendo dal DB i campi che mi servono
					if ($row === null)
						return null;

					foreach ($prendi as $k) { // Metto i dati ottenuti dal DB nelle opzioni, così posso usarli dopo
						$opt['fields'][$k] = $row[$k];

						if (isset($rule['options']['parent'][0]) and $k == $rule['options']['parent'][0]['field'])
							$node_parent = $row[$k];
					}
				}

				foreach ($opt['fields'] as $k => $v) {
					if (is_numeric($v) or is_string($v))
						$paradigma = str_replace('[el:' . $k . ']', isset($rule['options']['dontEncode']) ? $v : rewriteUrlWords([$v], $rule['options']['lowercase']), $paradigma);
				}

				if ($node_parent !== false)
					$cats[0] = $node_parent;
			}

			$return[$cr] = $paradigma;
		}

		ksort($return);
		return $return;
	}

	/**
	 * Retrieves required data from db (used by the getUrlFromRule method)
	 * Caches the result and uses cache if necessary
	 *
	 * @param string $table
	 * @param int $id
	 * @param string $field_id
	 * @param string|null $lang
	 * @return array|null
	 */
	private function getFromDb(string $table, int $id, string $field_id, string $lang = null): ?array
	{
		if (!isset($this->cache[$table][(string)$lang][$id]))
			$this->cache[$table][(string)$lang][$id] = $this->model->_Db->select($table, [$field_id => $id], ['lang' => $lang]) ?: null;

		return $this->cache[$table][(string)$lang][$id];
	}

	/**
	 * @param string $controller
	 * @return string|null
	 */
	public function getElementFor(string $controller): ?string
	{
		foreach ($this->rules as $rule) {
			if ($rule['controller'] === $controller)
				return $rule['options']['element'] ?: null;
		}
		return null;
	}
}
