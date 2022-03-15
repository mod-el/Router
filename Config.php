<?php namespace Model\Router;

use Model\Core\Module_Config;

class Config extends Module_Config
{
	/** @var array */
	private $routerRules = [];
	/** @var array */
	private $coreRules = ['rules' => [], 'controllers' => []];
	/** @var string */
	private $accetableCharacters = 'a-zа-я0-9_\p{Han}-';

	/**
	 * @throws \Exception
	 */
	protected function assetsList()
	{
		$this->addAsset('config', 'rules.php', function () {
			return "<?php\n\$router->addRule('Home', '');\n";
		});

		$this->addAsset('data', 'rules.php', function () {
			$this->makeCache();
			return null;
		});
	}

	/**
	 * Adds a rule - to be called in the config file ( app/Router/rules.php )
	 *
	 * @param string $controller
	 * @param array|string $url
	 * @param array $options
	 * @throws \Exception
	 */
	public function addRule(string $controller, $url, array $options = [])
	{
		$options = array_merge([
			'id' => 'id',
			'table' => null,
			'element' => null,
			'parent' => [],
			'tags' => [],
			'lowercase' => true,
			'if-null' => '',
		], $options);

		if (is_array($url)) {
			foreach ($url as $lang => $u) {
				$options['tags']['lang'] = $lang;
				$this->addRule($controller, $u, $options);
			}
		} else {
			$url_array = explode('/', $url);

			try {
				$orm = $this->model->load('ORM');
			} catch (\Exception $e) {
				$orm = null;
			}

			if ($orm) {
				$elements_tree = $orm->getElementsTree();

				if (preg_match('/\[el:[a-z0-9_-]+\]/i', $url)) {
					if (!$options['table']) {
						if (!$options['element'] and $elements_tree and isset($elements_tree['controllers'][$controller]) and $elements_tree['controllers'][$controller])
							$options['element'] = $elements_tree['controllers'][$controller];

						if ($options['element']) {
							$table = $elements_tree['elements'][$options['element']]['table'];
							if ($table)
								$options['table'] = $table;
						}
					}
				}

				if (preg_match('/\[p:[a-z0-9_-]+\]/i', $url)) {
					if (!preg_match('/\[el:[a-z0-9_-]+\]/i', $url))
						$this->model->error('In rule "' . entities($url) . '" is specified a category but not a element!');

					$parentLevelCount = 0;
					foreach ($url_array as $u) {
						if (preg_match('/\[p:[a-z0-9_-]+\]/i', $u))
							$parentLevelCount++;
					}

					if ($parentLevelCount > count($options['parent'])) {
						$options['parent'] = [];
						$el = $options['element'];

						if ($el) {
							while (count($options['parent']) < $parentLevelCount) {
								if (!empty($elements_tree['elements'][$el]) and $elements_tree['elements'][$el]['parent'] and $elements_tree['elements'][$el]['parent']['element']) {
									$field = $elements_tree['elements'][$el]['parent']['field'];
									$el = $elements_tree['elements'][$el]['parent']['element'];
									if ($elements_tree['elements'][$el]['table']) {
										$options['parent'][] = [
											'id' => 'id',
											'table' => $elements_tree['elements'][$el]['table'],
											'field' => $field,
										];
									} else {
										$this->model->error('Can\'t find one or more parent element/tables for the rule "' . entities($url) . '"! #1');
									}
								} else {
									$this->model->error('Can\'t find one or more parent element/tables for the rule "' . entities($url) . '"! #2');
								}
							}
						}

						$options['parent'] = array_reverse($options['parent']);
					}
				}
			}

			$this->routerRules[] = [
				'rule' => $url_array,
				'controller' => $controller,
				'options' => $options,
			];

			$simplifiedRule = preg_replace('/\[(el|p):' . $options['id'] . '\]/i', '[0-9]+', $url);
			$simplifiedRule = preg_replace('/\[(el|p):[a-z0-9_-]+\]/i', '[' . $this->accetableCharacters . ']*', $simplifiedRule);
			$simplifiedRule = str_replace('[*]', '[^?/]*', $simplifiedRule); // Backward compatibility

			$this->coreRules['rules'][] = $simplifiedRule;
			if (!in_array($controller, $this->coreRules['controllers']))
				$this->coreRules['controllers'][] = $controller;
		}
	}

	/**
	 * Imports and parses all the rules, and writes them in the cache file
	 *
	 * @return bool
	 */
	public function makeCache(): bool
	{
		$this->importRules();

		$cacheFile = INCLUDE_PATH . 'model' . DIRECTORY_SEPARATOR . 'Router' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'rules.php';
		return (bool)file_put_contents($cacheFile, '<?php
$rules = ' . var_export($this->routerRules, true) . ';
');
	}

	/**
	 * ORM must be updated in order for the cache to be properly updated
	 *
	 * @return array
	 */
	public function cacheDependencies(): array
	{
		return ['ORM'];
	}

	/**
	 * Returns all the rules for the Core to register
	 *
	 * @return array
	 */
	public function getRules(): array
	{
		$this->importRules();
		return $this->coreRules;
	}

	/**
	 * Imports the rules config file (that should consist in a series of "addRule" method calls)
	 */
	function importRules()
	{
		$this->coreRules = ['rules' => [], 'controllers' => []];
		$this->routerRules = [];
		$router = $this;
		if (file_exists(INCLUDE_PATH . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'Router' . DIRECTORY_SEPARATOR . 'rules.php'))
			require(INCLUDE_PATH . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'Router' . DIRECTORY_SEPARATOR . 'rules.php');
	}

	/**
	 * @param array $data
	 * @return bool
	 */
	public function init(?array $data = null): bool
	{
		return $this->makeCache();
	}

	public function getConfigData(): ?array
	{
		return [];
	}
}
