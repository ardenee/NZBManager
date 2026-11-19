<?php
function GetValidName($name)
{
	if(file_exists($name)) 
	{
		$parts = pathinfo($name);
		$num   = 1;	
						
		while(file_exists($parts['dirname']."/".$parts['filename']."(".$num.")".((strlen($parts['extension'])>0)?".":"").$parts['extension']))
			$num++;			
					
		$name = $parts['dirname']."/".$parts['filename']."(".$num.")".((strlen($parts['extension'])>0)?".":"").$parts['extension'];
	}
	
	return $name;
}
?>