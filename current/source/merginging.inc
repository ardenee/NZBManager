<?php
function DisplayFolders($NZB, $Folder)
{
	$num     = 1;
	$dirlist = $NZB->GetFolders($Folder);
	
	echo "<table width=100% onMouseover=\"changeto(event, 'c4c4c4')\" onMouseout=\"changeback(event, 'E9E9E9')\">\n";
	echo " <tr class=title1>\n";
	echo "	<td>Num</td>\n";
	echo "  <td>Name</td>\n";
	echo "  <td class=right>Options</td>\n";
	echo " </tr>\n";
	echo " <tr>\n";
	echo "  <td class=title2 colspan=3>Folders</td>\n";
	echo " </tr>\n";	
			
	foreach($dirlist as $key => $value)
	{	
		echo " <tr class=main1>\n";
		echo "  <td>$num</td>\n";
		echo "  <td>".substr($value, strlen($Folder))."</td>\n";
		echo "  <td class=right><a href=\"renamefolder.php?oldname=".$value."&from=renaming.php\">Rename Folder</a> - <a href=\"renamefiles.php?title=".substr($value, strlen($Folder))."\">Rename Files</a></td>\n";
		echo " </tr>\n";
		
		$num++;
	}

	echo "</table>";
}
?>