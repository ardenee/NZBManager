<?php
	$from = "index.php";
	
	//echo "<pre>";
	//print_r($_POST);
	//echo "</pre>";	
	//phpinfo(INFO_VARIABLES);

	if(isset($_GET["from"]))
		$from = $_GET["from"];	
	else if(isset($_POST["from"]))
		$from = $_POST["from"];	
		
	if(strlen($from)==0)
		$from = "index.php";

	if(count($_POST["oldname"])>0)
		{				
		foreach($_POST["oldname"] as $key=>$val)
		{			
			$val     = $_POST["newname"][$key];
			$key     = FilterOff($key);
			$oldfile = pathinfo($key);
			$oldname = $key;
			$newname = $oldfile['dirname']."/".$val;
				
			if($oldname != $newname and strlen($val)>0)
			{		
				$newfile = pathinfo($newname);

				if(strtolower($oldfile['extension']) != strtolower($newfile['extension']) and is_dir($oldname)==false)
					$newname = trim($newname, ".").".".$oldfile['extension'];
							
				if(is_file($oldname) or is_dir($oldname))
				{
					rename($oldname, GetNewName($newname));
					//echo $oldname."<BR>";
					//echo GetNewName($newname)."<HR>";
				}
			}
		}
	}
	
	header("Location: ".$from);
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
function GetNewName($name)
{
	$num = 1;
	
	while(is_file($name) or is_dir($name))
	{
		$name_parts = pathinfo($name);
		$name       = $name_parts['dirname']."/".$name_parts['filename']." (".$num.")";		
		
		if(strlen($name_parts['extension'])>0 and is_file($name));
			$name.=".".$name_parts['extension'];
			
		$num++;
	}
	
	return $name;
}
//----------------------------------------------------------------------------
?>