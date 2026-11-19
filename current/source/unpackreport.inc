<?php
include("db.php");
//--------------------------------------------------------------------------------
function GetUpdate($f)
{
	$d       = @file_get_contents($f);
	$order   = array("\r\n", "\n", "\r");
	$replace = "<BR>";
	$newstr  = str_replace($order, $replace, $d);
	
	$da = explode($replace, $newstr);
	$da = array_filter($da);// remove empty elements

	//foreach ($da as $key=>$value) // remove empty lines
	//	if(empty($value))
	//		unset($da[$key]);

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
		//if(empty($value))
		//	unset($da[$key]);
		//else
			$data.=$value."<BR>";

	return $data;
}
//--------------------------------------------------------------------------------
function strcontains($substring, $string) 
{
    $pos = stripos($string, $substring);
 
    if($pos === false)// string needle NOT found in haystack
        return false;
    else  // string needle found in haystack
        return true; 
}
//----------------------------------------------------------------------------
function CheckForMoreFiles()
{
	global $log;
	unlink($log);
	
	if(count($_POST["files"])>0)
	{	
		echo " <form action=unpack.php method=post name=unpack id=unpack>\n";
		
		foreach($_POST["files"] as $key => $val)
			echo "  <input type=hidden name=\"files[]\" value=\"$val\">\n";		
		
		echo "</form>\n";		
		echo "<body onload=\"UnpackNewFile()\">\n";		
	}
	else
		header("Location: unpacking.php");
}
//----------------------------------------------------------------------------
?>