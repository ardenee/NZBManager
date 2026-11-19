<?php
//----------------------------------------------------------------------------
function DisplayFolders($NZB, $Folder)
{
	$num = 1;
	$dirlist = $NZB->GetFolders($Folder);
	
	echo "<table width=100% onMouseover=\"changeto(event, 'c4c4c4')\" onMouseout=\"changeback(event, 'E9E9E9')\">\n";
	echo " <tr class=title1 id=\"ignore\">\n";
	echo "	<td>Num</td>\n";
	echo "  <td>Name</td>\n";
	echo "  <td class=right>Options</td>\n";
	echo " </tr>\n";
	echo " <tr>\n";
	echo "	<td class=title2 colspan=3>Folders</td>\n";
	echo " </tr>\n";	
		
	foreach($dirlist as $key => $value)
	{	
		echo " <tr class=main1>\n";
		echo "  <td>$num</td>\n";
		echo "  <td class=bold>".substr($value, strlen($Folder))."</td>\n";
		echo "  <td class=right><a href=\"renamefolder.php?oldname=".$value."&from=renaming.php\">Rename Folder</a> - <a href=\"renamefiles.php?title=".substr($value, strlen($Folder))."\">Rename Files</a> - <a href=\"javascript:DeleteFolder('$value','renaming.php');\">Delete Folder</a></td>\n";
		echo " </tr>\n";
		$num++;
	}

	echo " <tr>\n  <td class=title2 colspan=3>Files</td>\n </tr>\n";
	
	$files = $NZB->GetFiles(rtrim($Folder, '/'), null, false);
	
	foreach($files as $key => $value)
	{	
		echo " <tr class=main1>\n";
		echo "	<td>$num</td>\n";
		echo "  <td>".substr($value, strlen($Folder))."</td>\n";
		echo "	<td class=right><a href=\"renamefile.php?oldname=".$value."&from=renaming.php\">Rename File</a> - <a href=\"javascript:DeleteFile('$value','renaming.php');\">Delete File</a></td>\n";
        echo " </tr>\n";
		$num++;
	}
	
	echo "</table>";
}
//----------------------------------------------------------------------------
?>