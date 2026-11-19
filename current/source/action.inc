<?php
//--------------------------------------------------------------------------------
function GetUpdate($f)
{
	$d       = @file_get_contents($f);
	$order   = array("\r\n", "\n", "\r");
	$replace = "<BR>";
	$newstr  = str_replace($order, $replace, $d);
	
	$da = explode("<BR>", $newstr);

	foreach ($da as $key=>$value) // remove empty lines
		if(empty($value))
			unset($da[$key]);

	return array_pop($da); // return last line
}
//--------------------------------------------------------------------------------
function GetUpdateFrom($data)
{
	$da = explode("<BR>", $data); // works on 

	foreach ($da as $key=>$value) // remove empty lines
		if(empty($value))
			unset($da[$key]);

	return array_pop($da); // return last line
}
//--------------------------------------------------------------------------------
function GetUnpackUpdateFrom($data)
{
	$da = explode("<BR>", $data);

	foreach ($da as $key=>$value) // remove empty lines
		if(empty($value) or strcontains("... ", $value))
			unset($da[$key]);

	return array_pop($da); // return last line
}
//--------------------------------------------------------------------------------
function GetError($f)
{
	$d    = @file_get_contents($f);	
	$order   = array("\r\n", "\n", "\r");
	$replace = "<BR>";
	$newstr = str_replace($order, $replace, $d);

	$da   = explode("<BR>", $newstr);	
	$data = "";
		
	foreach($da as $key=>$value)
		if(empty($value))
			unset($da[$key]);
		else
			$data.=$value."<BR>";

	return $data;
}
//--------------------------------------------------------------------------------
function GetRepairFileCount($f)
{
	$d = @file_get_contents($f);	
	
	return substr_count($d, "Target:");
}
//--------------------------------------------------------------------------------
function GetRepairFileCountFrom($data)
{
	return substr_count($data, "Target:");
}
//--------------------------------------------------------------------------------
function GetUnpackFileCount($f)
{
	$d = @file_get_contents($f);	
	
	return substr_count($d, "Extracting from ");
}
//--------------------------------------------------------------------------------
function GetUnpackFileCountFrom($data)
{	
	return substr_count($data, "Extracting from ");
}
//--------------------------------------------------------------------------------

?>