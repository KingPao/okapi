<?php

namespace okapi\services\apiref\method;

use Exception;
use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;

class WebService
{
	public static function options()
	{
		return array(
			'consumer'   => 'ignored',
			'token'      => 'ignored',
		);
	}
	
	private static function arg_desc($arg_node)
	{
		$attrs = $arg_node->attributes();
		return array(
			'name' => (string)$attrs['name'],
			'is_required' => $arg_node->getName() == 'req',
			'default_value' => isset($attrs['default']) ? (string)$attrs['default'] : null,
			'description' => self::get_inner_xml($arg_node)
		);
	}
	
	private static function get_inner_xml($node)
	{
		$s = $node->asXML();
		$start = strpos($s, ">") + 1;
		$length = strlen($s) - $start - (3 + strlen($node->getName()));
		return substr($s, $start, $length);
	}
	
	public static function call(OkapiRequest $request)
	{
		$methodname = $request->get_parameter('name');
		if (!$methodname)
			throw new ParamMissing('name');
		if (!preg_match("#^services/[0-9a-z_/]*$#", $methodname))
			throw new InvalidParam('name');
		if (!OkapiServiceRunner::exists($methodname))
			throw new InvalidParam('name', "Method does not exist: '$methodname'.");
		$options = OkapiServiceRunner::options($methodname);
		$docs = simplexml_load_string(OkapiServiceRunner::docs($methodname));
		$result = array(
			'name' => $methodname,
			'short_name' => end(explode("/", $methodname)),
			'ref_url' => $GLOBALS['absolute_server_URI']."okapi/$methodname.html",
			'auth_options' => array(
				'consumer' => $options['consumer'],
				'token' => $options['token'],
			)
		);
		if (!$docs->brief)
			throw new Exception("Missing <brief> element in the $methodname.xml file.");
		if ($docs->brief != self::get_inner_xml($docs->brief))
			throw new Exception("The <brief> element may not contain HTML markup ($methodname.xml).");
		if (strlen($docs->brief) > 80)
			throw new Exception("The <brief> description may not be longer than 80 characters ($methodname.xml).");
		if (strpos($docs->brief, "\n") !== false)
			throw new Exception("The <brief> element may not contain new-lines ($methodname.xml).");
		$result['brief_description'] = self::get_inner_xml($docs->brief);
		if (!$docs->desc)
			throw new Exception("Missing <desc> element in the $methodname.xml file.");
		$result['description'] = self::get_inner_xml($docs->desc);
		$result['arguments'] = array();
		foreach ($docs->req as $arg) { $result['arguments'][] = self::arg_desc($arg); }
		foreach ($docs->opt as $arg) { $result['arguments'][] = self::arg_desc($arg); }
		if (!$docs->returns)
			throw new Exception("Missing <returns> element in the $methodname.xml file. ".
				"If your method does not return anything, you should document in nonetheless.");
		$result['returns'] = self::get_inner_xml($docs->returns);
		return Okapi::formatted_response($request, $result);
	}
}