<?php
//----------------------------------------------------------------------------
function DisplayFolders($NZB, $Folder)
{
	$num     = 1;	
	$dirlist = $NZB->GetFolders($Folder);
	
	echo "<table width=100% onMouseover=\"changeto(event, 'c4c4c4')\" onMouseout=\"changeback(event, 'E9E9E9')\">\n";
	echo " <form action=merge.php name=merge id=merge method=post>\n";
	echo " <tr class=title1 id=\"ignore\">\n";
	echo "  <td>Num</td>\n";
	echo "  <td>Name</td>\n";
	echo "  <td class=right>Options</td>\n";
	echo " </tr>\n";
	echo " <input type=\"hidden\" id=\"folder\" name=\"folder\" value=\"\">\n";
		
	foreach($dirlist as $key => $value)
	{	
		echo " <tr class=main1>\n";
		echo " <td>$num <input type=checkbox name=\"folders[]\" value=\"$value\"></td>\n";
		echo " <td class=bold>".substr($value, strlen($Folder))."</td>\n";
		echo " <td class=right><a href=\"#\" onclick=\"handleClick('".$value."')\">Merge Selected</a></td>\n";
		echo " </tr>\n";
		
		$num++;
	}
	
	echo " </form>\n";
	echo "</table>";
}
//----------------------------------------------------------------------------
?>