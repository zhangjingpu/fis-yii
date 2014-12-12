<?php

namespace ext\fis;


/**
 * CController -> render/renderPartical -> renderFile
 * CController -> layout
 *
 */
class FisController extends \CController{

	protected $fisLoader;

	public function __construct($id, $module=null){
		parent::__construct($id, $module);
		$this->fisLoader = new FisLoader();
	}

	public function render($view,$data=null,$return=false){
		$output = parent::render($view, $data, true);
		$output = $this->fisLoader->load($output);
		if($return)
			return $output;
		else
			echo $output;
	}

	public function renderPartial($view, $data=null, $return=false, $processOutput=false){
		$output = parent::renderPartial($view, $data, true, $processOutput);
		if($return)
			return $output;
		else{//直接输出时再处理fis加载文件问题，做为返回值时不处理，交由下步处理, 比如render调用了
			//此方法，return出去时处理了相当于处理了多次
			$output = $this->fisLoader->load($output);
			echo $output;
		}
	}

	public function requireJs($id){
		$this->fisLoader->requireJs($id);
	}

	public function loadCss($cssFile){
		$this->fisLoader->loadCss($cssFile);
	}
}


class FisLoader{

	private $body = '</body>';
	private $head = '</head>';

	private $required = array(
		'css' => array(),
		'js' => array(),
	);
	private $noDepends = array();
	private $_m = array(
		'css' => array(),
		'js' => array(),
		'inlineJs' => array(),
		'inlineCss' => array(),
		'resoureJs' => array(),
		'resoureCss' => array(),
	);

	private $map;

	public function load($html){
		if(($map = $this->getMap()) !== false){
			$html = $this->resolveCss($html);			
			$html = $this->resolveJs($html);
			$html = $this->resolveInlineJs($html);
			$html = $this->resolveInlineCss($html);

			$this->getDepends();

			$html = $this->addSyncCss($html);
			$html = $this->addSyncJs($html);

			return $html;	
		}
		else
			return $html;
	}

	private function getMap(){
		if($this->map)
			return $this->map;

		$mapFile = \Yii::getPathOfAlias('application') . '/map.json';
		if(file_exists($mapFile)){
			$this->map = new Map(file_get_contents($mapFile));
			return $this->map;
		}			
		else
			return false;
	}

	private function push($id, $type){
		if(is_string($id) && !in_array($id, $this->_m[$type])){
			$this->_m[$type][] = $id;
			return;
		}

		if(is_array($id)){
			foreach($id as $_id){
				$this->push($_id, $type);
			}
		}
	}

	public function requireJs($id){
		$this->push($id, 'js');
		if(! in_array($id, $this->required['js']))
			$this->required['js'][] = $id;
	}

	public function loadCss($id){
		$this->push($id, 'css');
	}

	public function getDepends(){
		$map = $this->getMap();
		$needLoadScripts = array_merge($this->_m['js'], $this->_m['css']);
		$nodeColl = $this->searchDepends($needLoadScripts);

		//首先加载没有依赖的文件
		foreach($this->noDepends as $uri){
			if(in_array($uri, $this->_m['js']))
				$this->_m['resoureJs'][] = $uri;
			else
				$this->_m['resoureCss'][] = $uri;
		}

		foreach($nodeColl as $id){
			$type = $map->getType($id);
			$uri = $map->getUri($id);
			if($type === 'js')
				$this->_m['resoureJs'][] = $uri;
			if($type === 'css')
				$this->_m['resoureCss'][] = $uri;
		}
	}

	public function searchDepends($id){
		$nodeColl = new NodeCollection();

		$_stack = is_array($id) ? $id : array($id);
		$depends = array();
		$map = $this->getMap();

		while (! empty($_stack)){
			$id = array_shift($_stack);
			$oldId = $id;
			$id = $map->hasId($id) ? $id : $map->getId($id); //处理时uri的情况

			if($id === false){
				$this->noDepends[] = $oldId;
				continue;
			}

			if(! in_array($id, $depends)){
				$depends[] = $id;
				$nodeColl->add(new Node($id));

				if(($dps = $this->getMap()->getDepends($id)) !== false){
					foreach($dps as $dp){
						array_push($_stack, $dp);
						$nodeColl->add(new Node($dp), $id);
					}
				}
			}
		}
		return $nodeColl;
	}

	public function resolveJs($html){
		$pattern = '/<script.*src=\s*["\']\s*(.*)\s*["\'][^>]*><\/script>/U';
		preg_match_all($pattern, $html, $matches);

		if(count($matches) > 1){
			$this->push($matches[1], 'js');
		}
		return preg_replace($pattern, '', $html);
	}

	public function resolveInlineJs($html){
		$pattern = '/<script\s*type=\s*[\'"]\s*[^\'"]*\s*["\']\s*>(.*?)<\/script>/s';
		preg_match_all($pattern, $html, $matches);

		if(count($matches) > 1){
			$this->push($matches[1], 'inlineJs');
		}
		return preg_replace($pattern, '', $html);
	}

	public function resolveCss($html){
		$pattern = '/<link.*\s*href=\s*[\'"]\s*(.*)\s*[\'"].*>/';
		preg_match_all($pattern, $html, $matches);
		if(count($matches) > 1)
			$this->push($matches[1], 'css');
		return preg_replace($pattern, '', $html);
	}

	public function resolveInlineCss($html){
		$pattern = '/<style[^>]*>(.*?)<\/style>/s';
		preg_match_all($pattern, $html, $matches);
		if(count($matches) > 1){
			$this->push($matches[1], 'inlineCss');
		}
		return preg_replace($pattern, '', $html);
	}

	public function addSyncJs($html){
		$script = '<script type="text/javascript" src="';
		$script .= implode($this->_m['resoureJs'], '"></script><script type="text/javascript" src="');
		$script .= '"></script>';
		$script .= '<script type="text/javascript">';
		
		if(count($this->required['js']) > 0){
			$script .= 'require("';
			$script .= implode($this->required['js'], '");require("');	
			$script .= '");';
		}

		$script .= implode($this->_m['inlineJs'], "\n");
		$script .= '</script>';
		$script .= $this->body;
		return str_replace($this->body, $script, $html);
	}

	public function addSyncCss($html){
		$link = '<link rel="stylesheet" type="text/css" href="';
		$link .= implode($this->_m['resoureCss'], '"><link rel="stylesheet" type="text/css" href="');
		$link .= '">';
		$link .= '<style type="text/css">';
		$link .= implode($this->_m['inlineCss'], "\n");
		$link .= '</style>';
		$link .= $this->head;
		return str_replace($this->head, $link, $html);
	}
}

class Node{
	public $id;
	public $pre = null;
	public $next = null;

	public function __construct($id){
		$this->id = $id;
	}

	public function insertBefore($node){
		$node->pre = $this->pre;
		if($node->pre !== null)
			$this->pre->next = $node;
		$this->pre = $node;
		$node->next = $this;
	}

	public function insertAfter($node){
		$node->next  = $this->next;
		if($node->next !== null)
			$this->next->pre = $node;
		$this->next = $node;
		$node->pre = $this;
	}
}

class NodeCollection implements \Iterator{

	private $iter;

	public $head = null;
	public $tail = null;

	public $ids = array();
	private $position = 0;

	public function add($node, $overId=null){
		if($this->head === null){
			$this->head = $this->tail = $node;
		}else{

			if(isset($this->ids[$node->id])){
				return;
			}
			if($overId === null){
				$this->tail->insertAfter($node);
				$this->tail = $node;
			}else{
				$overNode = $this->ids[$overId];
				$overNode->insertBefore($node);
				if($this->head == $overNode)
					$this->head = $overNode->pre;
			}
		}

		$this->ids[$node->id] = $node;
	}



	public function current(){
		return $this->iter->id;
	}

	public function key(){
		return $this->position;
	}

	public function next(){
		$this->iter = $this->iter->next;
		++$this->position;
	}

	public function rewind(){
		$this->iter = $this->head;
		$this->position = 0;
	}

	public function valid(){
		return $this->iter !== null;
	}

}

class Map{

	private $pkg = array();
	private $res = array();
	private $reverseMap = array();

	public function __construct($map){
		$map = json_decode($map, true);
		$this->pkg = $map['pkg'];
		$this->res = $map['res'];
		$this->reverseMap = $this->reverseMap();
	}

	/**
	 * 反转map.json， 让uri对应id
	 * @param $map map.json解析的数组
	 * @return array
	 */
	public function reverseMap(){
		$reverseMap = array();
		foreach($this->res as $id => $setting){
			$reverseMap[$setting['uri']] = $id;
		}
		foreach($this->pkg as $id => $pkg){
			$reverseMap[$pkg['uri']] = $id;
		}
		return $reverseMap;
	}

	public function hasId($id){
		return isset($this->res[$id]);
	}

	public function getDepends($id){
		return isset($this->res[$id]) && isset($this->res[$id]['deps']) ? $this->res[$id]['deps'] : false;
	}

	public function getUri($id){
		return isset($this->res[$id]) && isset($this->res[$id]['uri']) ? $this->res[$id]['uri'] : false;
	}

	public function getType($id){
		return isset($this->res[$id]) && isset($this->res[$id]['type']) ? $this->res[$id]['type'] : false;
	}

	public function getId($url){
		return isset($this->reverseMap[$url]) ? $this->reverseMap[$url] : false;
	}

	public function getPkg($id){
		return isset($this->res[$id]) && isset($this->res[$id]['pkg']) ? $this->res[$id]['pkg'] : false;
	}

	public function getPkgUri($pkg){
		return isset($this->pkg[$pkg]['uri']) ? $this->pkg[$pkg]['uri'] : false;
	}

	public function getPkgMembers($pkg){
		return isset($this->pkg[$pkg]['has']) ? $this->pkg[$pkg]['has'] : false;
	}

	public function getPkgType($pkg){
		return isset($this->pkg[$pkg]['type']) ? $this->pkg[$pkg]['type'] : false;
	}

}