<?php
//----------------------------------------------------------------------------
function GetFileName($Folder, $File, $folder)
{
	return substr($File, strlen($folder)+1);
}
?>