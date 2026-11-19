<?php
function GetNewFiles($NZB, $Title)
{
	$Title   = $_GET["title"];
	$result  = mysql_query("SELECT id FROM hashes WHERE title='".$Title."' LIMIT 1;", DBLINK);
	
	$id      = mysql_fetch_row($result); 
	$result  = mysql_query("SELECT subject FROM files WHERE hashid=".$id[0].";", DBLINK);
	$dbfiles = array();

	while($data = @mysql_fetch_row($result))
		$dbfiles[] = NameOnly(StripName($data[0])); 
			
	$filesFound = $NZB->GetAllFiles($NZB->Settings[9].$Title, null);

	foreach($filesFound as $key => $value)
		$filesFound[$key] = NameOnly($value);
			
	$newarray = array_diff($filesFound, $dbfiles);
	sort($newarray);
		
	return $newarray;
}
//----------------------------------------------------------------------------
function StripName($name)
{
	$nname = $name;
	$nname = substr($name, (strpos($name, "\"")+1));
	$nname = substr($nname, 0, strrpos($nname, "\""));		
	
	return $nname;	
}
//----------------------------------------------------------------------------
function GetNewName($name, $Title, $Folder)
{
	$fileext = pathinfo($name, PATHINFO_EXTENSION);
	
	return $Folder.$Title."/".$Title.".".$fileext;
}
//----------------------------------------------------------------------------
function NameOnly($name)
{
	$nname = $name;
	$nname = substr($nname, strrpos($nname, "/"));		
	
	return trim($nname, "/");	
}
//----------------------------------------------------------------------------
function PathOnly($name)
{
	$name = substr($name, 0, strrpos($name, "/"));		
	
	return trim($name)."/";	
}
//----------------------------------------------------------------------------
function FilterOn($name)
{
	$nname = str_ireplace("[", "{{", $name);
	$nname = str_ireplace("]", "}}", $nname);
	
	return $nname;
}
//----------------------------------------------------------------------------
function FilterOff($name)
{
	$nname = str_ireplace("{{", "[", $name);
	$nname = str_ireplace("}}", "]", $nname);
	
	return $nname;
}
//----------------------------------------------------------------------------
function CleanUpName($Name)
{
	global $nzb;
	
	$file_parts = pathinfo($Name);	
	$a          = explode(":", $nzb->Settings[14]);
	$nname      = str_ireplace(".", "@", $file_parts['filename']);
	
	foreach($a as $key =>$val)
	{
		$nname = str_ireplace("@".$val, "", $nname);
		$nname = str_ireplace($val."@", "", $nname);
		$nname = str_ireplace("-".$val, "", $nname);
		$nname = str_ireplace($val."-", "", $nname);
	}
	
	$nname = str_ireplace("  ", "", $nname);
	$nname = str_ireplace("@", " ", $nname);
	$nname = trim($nname, "-");
	$nname = trim($nname, ".");
	$nname = trim($nname);
	
	if(strlen($file_parts['extension'])>0)
		$nname = $nname.".".strtolower($file_parts['extension']);	

	return $nname;
}
//----------------------------------------------------------------------------
?>