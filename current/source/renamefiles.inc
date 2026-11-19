<?php
function GetNewFiles($NZB, $Title)
{
	$Title   = $_GET["title"];
	$result  = mysql_query("SELECT id FROM hashes WHERE title='".$Title."' LIMIT 1;", DBLINK);
	
	$id      = mysql_fetch_row($result); 
	$result  = mysql_query("SELECT subject FROM files WHERE hashid=".$id[0].";", DBLINK);
	$dbfiles = array();

	while($data = @mysql_fetch_row($result))
		$dbfiles[] = $data[0];
	
	$filesFound = $NZB->GetAllFiles($NZB->Settings[9].$Title, null);
	$newarray = array();
	
	foreach($filesFound as $key => $value)
	{
		$in        = false;
		$formatted = false;
			
		foreach($dbfiles as $key2 => $value2)
		{
			$name = $NZB->StripName($value2);
			
			if(strlen($name)==strlen(NameOnly($value)))
				$formatted = true;
			
			if(contains(NameOnly($value), $name) and $formatted)// and strlen(NameOnly($value)) != strlen($value2))
			{
				$in = true;
				break;
			}
			else
			{
				$in=false;
				$formatted = false;
			}	
		}
			
		if(!$in)
			$newarray[] = $value;
	}
			
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
function FilterOff($name)
{
	$nname = str_ireplace("{{", "[", $name);
	$nname = str_ireplace("}}", "]", $nname);
	
	return $nname;
}
//----------------------------------------------------------------------------
function FilterOn($name)
{
	$nname = str_ireplace("[", "{{", $name);
	$nname = str_ireplace("]", "}}", $nname);
	
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
	$nname = $name;
	$nname = substr($nname, 0, strrpos($nname, "/"));		
	
	return $nname."/";	
}
//----------------------------------------------------------------------------
function CleanUpName($Name)
{
	$file_parts = pathinfo($Name);	
	$a          = array("HDTV", "x264", "2hd", "720p", "1080p", "WEB", "DL", "DD5", "H264", "iT00NZ","ASAP", "LOL", "CRiMSON", "2012", "NGB", "264");
//HDTV:x264:2hd:720p:1080p:WEB:DL:DD5:H264:iT00NZ:ASAP:LOL:CRiMSON:2012:NGB:264	
	$nname = str_ireplace(".", "@", $file_parts['filename']);
	
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
		$nname = $nname.".".$file_parts['extension'];	
	
	return $nname;
}

?>