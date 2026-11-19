<?php
$folder = $_POST["folder"];

foreach($_POST["files"] as $key => $val)
{
	$newname = $folder ."/".StripPath($val); 	

	if(file_exists($newname))
	{
		$num       = 1;						
		$fileparts = pathinfo($newname);
				
		while(file_exists($fileparts['dirname']."/".$fileparts['filename']."(".$num.").".$fileparts['extension']))
			$num++;					
			
		$newname = $fileparts['dirname']."/".$fileparts['filename']."(".$num.").".$fileparts['extension'];
	}
	
	rename($val, $newname);
}
//--------------------------------------------------------------------------------
function StripPath($Folder)
{
	return substr($Folder, strrpos($Folder, "/")+1);	
}
//--------------------------------------------------------------------------------
header('Location: moving.php');
?>