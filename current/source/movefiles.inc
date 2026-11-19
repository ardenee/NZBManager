<?php
//--------------------------------------------------------------------------------
function DisplayRootFolders($Folders)
{
	$folders = explode(":", $Folders);
	echo " <table width=100% cellspace=1>\n";
	echo "  <tr class=title2 id=ignore>\n";
	echo "   <td colspan=2>Root Folders</td>\n";
	echo "  </tr>\n";	
	$num=1;
	
	foreach($folders as $key => $val)
	{	
		echo "  <tr class=main1>\n";
		echo "  <td>$num</td>\n";
		echo "   <td><a href=\"javascript:MoveClick('$val');\">$val</a></td>\n";
		echo "  </tr>\n";		
		$num++;
	}
	
	echo " </table>\n";
}
//--------------------------------------------------------------------------------
function DisplayMoveToFolders($Folder, $NZB)
{
	$folders = array();
	$folders = $NZB->GetFolders(rtrim($Folder, '/'));	
	echo " <table>\n";
	echo "   <td class=title2 colspan=2>Parent</td>\n";
	echo "  <tr>\n";
	echo "  <tr class=main1 id=\"ignore\">\n";
	echo "   <td>&nbsp</td>\n";
	echo "   <td><a href=\"javascript:MoveClick('".GetRootFolderLink($Folder, $NZB->Settings[5])."');\"><b>".GetRootFolderLink($Folder, $NZB->Settings[5])."</b></a></td>\n";
	echo " <tr>\n";
	$num = 1;
	
	echo " <tr>\n";
	echo "   <td class=title2 colspan=2>Viewing Folder '$Folder'</td>\n";
	echo " </tr>\n";
	
	
	foreach($folders as $key => $val)
	{
		if(strcontains("#", $val) or strcontains("@", $val))
			continue;
			
		echo " <tr class=main1small>\n";
		echo "  <td>$num</td>\n";
		echo "  <td><a href=\"javascript:MoveClick('$val');\">".StripPath($val)."</a></td>\n";
		echo " <tr>\n";
		$num++;
	}
	
	echo " </table>\n";
}
//--------------------------------------------------------------------------------
function GetRootFolderLink($Folder, $Folders)
{
	return substr($Folder, 0, strrpos($Folder, "/"));	
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
//--------------------------------------------------------------------------------
function strcontainsArray($substring, $string) 
{
	if(strlen($substring)==0)
		return false; 
		
	$folders = explode(":", $string);
	
	foreach($folders as $key => $val)
	{	
		$pos = stripos($substring, $val);
	 
		if($pos === false)
			continue;
		else  
			return true; 
	}
	
	return false;
}
//--------------------------------------------------------------------------------
function FilesToMove($files)
{
	//$folders = explode(":", $Folders);
	echo " <table width=100% cellspace=1>\n";
	echo "   <td class=title2>Files To Move!</td>\n";
	
	foreach($files as $key => $val)
	{	
		echo "  <tr class=\"main1\">\n";
		echo "   <td>$val</a></td>\n";
		echo "  </tr>\n";
	}
	
	echo " </table>\n";
}
//--------------------------------------------------------------------------------
function StripPath($Folder)
{
	return substr($Folder, strrpos($Folder, "/")+1);	
}
//--------------------------------------------------------------------------------
?>
