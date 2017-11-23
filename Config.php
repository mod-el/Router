<?php namespace Model\Router;

use Model\Core\Module_Config;

class Config extends Module_Config {
	/** @var array */
	private $routerRules = [];
	/** @var array */
	private $coreRules = ['rules'=>[], 'controllers'=>[]];
	/** @var string */
	private $accetableCharacters = 'a-zа-я0-9_\p{Han}-';

	/**
	 * Adds a rule - to be called in the config file ( app/Router/rules.php )
	 *
	 * @param string $controller
	 * @param array|string $url
	 * @param array $options
	 */
	public function addRule($controller, $url, array $options=[]){
		$options = array_merge([
			'id'=>'id',
			'table'=>null,
			'element'=>null,
			'parent'=>[],
			'tags'=>[],
			'lowercase'=>true,
			'if-null'=>'',
		], $options);

		if(is_array($url)){
			foreach($url as $lang=>$u){
				$options['tags']['lang'] = $lang;
				$this->addRule($controller, $u, $options);
			}
		}else{
			$url_array = explode('/', $url);

			if($this->model->moduleExists('ORM')){
				$elements_tree = $this->model->_ORM->getElementsTree();

				if(preg_match('/\[el:[a-z0-9_-]+\]/i', $url)){
					if(!$options['table']){
						if(!$options['element'] and $elements_tree and isset($elements_tree['controllers'][$controller]) and $elements_tree['controllers'][$controller])
							$options['element'] = $elements_tree['controllers'][$controller];

						if(!$options['element'])
							$this->model->error('Can\'t find an element to load for the rule "'.entities($url).'"!');

						$table = $elements_tree['elements'][$options['element']]['table'];
						if($table)
							$options['table'] = $table;
						else
							$this->model->error('Can\'t find a table to attach to the rule "'.entities($url).'"!');
					}
				}

				if(preg_match('/\[cat:[a-z0-9_-]+\]/i', $url)){
					if(!preg_match('/\[el:[a-z0-9_-]+\]/i', $url))
						$this->model->error('In rule "'.entities($url).'" is specified a category but not a element!');

					$parentLevelCount = 0;
					foreach($url_array as $u){
						if(preg_match('/\[cat:[a-z0-9_-]+\]/i', $u))
							$parentLevelCount++;
					}

					if($parentLevelCount>count($options['parent'])){
						$options['parent'] = [];
						$el = $options['element'];

						while(count($options['parent'])<$parentLevelCount){
							if($elements_tree['elements'][$el]['parent'] and $elements_tree['elements'][$el]['parent']['element']){
								$field = $elements_tree['elements'][$el]['parent']['field'];
								$el = $elements_tree['elements'][$el]['parent']['element'];
								if($elements_tree['elements'][$el]['table']){
									$options['parent'][] = [
										'id'=>'id',
										'table' => $elements_tree['elements'][$el]['table'],
										'field' => $field,
									];
								}else{
									$this->model->error('Can\'t find one or more parent element/tables for the rule "'.entities($url).'"!');
								}
							}else{
								$this->model->error('Can\'t find one or more parent element/tables for the rule "'.entities($url).'"!');
							}
						}

						$options['parent'] = array_reverse($options['parent']);
					}
				}
			}

			$this->routerRules[] = [
				'rule'=>$url_array,
				'controller'=>$controller,
				'options'=>$options,
			];

			$simplifiedRule = preg_replace('/\[(el|cat):'.$options['id'].'\]/i', '[0-9]+', $url);
			$simplifiedRule = preg_replace('/\[(el|cat):[a-z0-9_-]+\]/i', '['.$this->accetableCharacters.']*', $simplifiedRule);
			$simplifiedRule = str_replace('[*]', '[^?/]*', $simplifiedRule); // Backward compatibility

			$this->coreRules['rules'][] = $simplifiedRule;
			if(!in_array($controller, $this->coreRules['controllers']))
				$this->coreRules['controllers'][] = $controller;
		}
	}

	/**
	 * Imports and parses all the rules, and writes them in the cache file
	 *
	 * @return bool
	 */
	public function makeCache(){
		$this->importRules();

		$cacheFile = INCLUDE_PATH.'model'.DIRECTORY_SEPARATOR.'Router'.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'rules.php';
		$scrittura = file_put_contents($cacheFile, '<?php
$rules = '.var_export($this->routerRules, true).';
');
		if(!$scrittura)
			return false;

		return true;
	}

	/**
	 * Returns all the rules for the Core to register
	 *
	 * @return array
	 */
	public function getRules(){
		$this->importRules();
		return $this->coreRules;
	}

	/**
	 * Imports the rules config file (that should consist in a series of "addRule" method calls)
	 */
	function importRules(){
		$this->coreRules = ['rules'=>[], 'controllers'=>[]];
		$this->routerRules = [];
		$router = $this;
		if(file_exists(INCLUDE_PATH.'app'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'Router'.DIRECTORY_SEPARATOR.'rules.php'))
			require(INCLUDE_PATH.'app'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'Router'.DIRECTORY_SEPARATOR.'rules.php');
	}

	/**
	 * @param array $data
	 * @return mixed
	 */
	public function install(array $data = []){
		if(!is_dir(INCLUDE_PATH.'app'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'Router'))
			mkdir(INCLUDE_PATH.'app'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'Router');
		if(!file_exists(INCLUDE_PATH.'app'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'Router'.DIRECTORY_SEPARATOR.'rules.php'))
			file_put_contents(INCLUDE_PATH.'app'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'Router'.DIRECTORY_SEPARATOR.'rules.php', "<?php\n\$router->addRule('Home', '');\n");
		$this->makeCache();
		return true;
	}
}