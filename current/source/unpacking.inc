<?php
//----------------------------------------------------------------------------   
function DisplayFiles($Folder, $NZB, $Types)
{
	$files = $NZB->GetFiles(rtrim($Folder, '/'), $Types, true);	
	$files = SetUpGroups($files);	

	$data = " <table width=100% onMouseover=\"changeto(event, '#c4c4c4')\" onMouseout=\"changeback(event, '#E9E9E9')\">\n";
	$data.= "  <tr class=title1 id=ignore>\n";
	$data.= "   <td>Num</td>\n";
	$data.= "   <td>Folder</td>\n";
	$data.= "   <td>Location</td>\n";
	$data.= "   <td class=right>Options</td>\n";
	$data.= "  </tr>\n";	
	$num  = 1;	
	
	foreach($files as $key => $value)
	{	
		$mf = MainFolderOnly(FolderOnly($value[0]));
		$fo = FolderOnly($value[0]);

		if($key==rtrim($NZB->Settings[9], "/"))
		{
			$mf = MainFolderOnly($key);	
			$fo = $key;
		}
			
		$data.= "  <form name=\"unpack$num\" method=post id=\"unpack$num\" action=unpack.php>\n";
		$data.= "  <tr class=main1>\n";
		$data.= "   <td>$num</td>\n";
		$data.= "   <td><a onclick=\"displayRow('".$num."')\" style=\"cursor:hand; cursor:pointer\"><B>".$mf."</B></a></td>\n";			
		$data.= "   <td>".$fo."/</td>\n";
		$data.= "   <td class=right><a href=\"javascript:UnpackClick('unpack$num');\">Unpack All</a></td>\n";
		$data.= "  </tr>\n";			
		$data.=  "  <tbody id='$num'>\n";			
		$data.= "   <tr class=title2 id=ignore>\n";
		$data.= "    <td class=right>Num</td>\n";
		$data.= "    <td>File</td>\n";
		$data.= "    <td class=right>Size - Date</td>\n";
		$data.= "    <td class=right>Options</td>\n";			
		$data.= "   </tr>\n";
		$num2 = 1;
				
		foreach($value as $k => $v)
		{	
			if($fo == FolderOnly($v))
			{
				//if(contains(".part02.rar", $v) or contains(".part002.rar", $v))
				//	break;
					// if(preg_match('/.*\.part[0]+1\.rar/i', $v, $m) || (!preg_match('/.*\.part[0-9]+\.rar/i',$v) && preg_match('/.*\.rar/i', $v, $m))) 
				if(preg_match('/.*\.part[0]+1\.rar/i', $v)  || 
				   preg_match('/.*\.part[00]+1\.rar/i', $v) || 
				 (!preg_match('/.*\.part[0-9]+\.rar/i',$v)  && preg_match('/.*\.rar/i', $v)) || 
				 (!preg_match('/.*\.part[00-9]+\.rar/i',$v) && preg_match('/.*\.rar/i', $v)) ||
				   preg_match('/.*\.tar/i', $v) || preg_match('/.*\.zip/i', $v) || preg_match('/.*\.tgz/i', $v))
				{	 
					$data.= "   <input type=hidden name=\"files[]\" value=\"$v\">\n";
					$data.= "   <tr class=main1small>\n";
					$data.= "    <td class=right>$num2</td>\n";
					$data.= "    <td>$v</td>\n";
					$data.= "    <td class=right>".number_format(filesize($v)/1024)." KB - ".date("Y-m-d H:i:s", fileatime($v))."</td>\n";
					$data.= "    <td class=right><a href=\"unpack.php?file=".urlencode($v)."\">Unpack</a></td>\n";			
					$data.= "   </tr>\n";
					$num2++;
				}
			}
		}
			
		$data.= "  </form>\n";		
		$data.= "  </tbody>\n";		
		$num++;		
	}
	
	$data.= "  </table>\n";	
	echo $data;	
	
	return count($files);
}
//----------------------------------------------------------------------------
function FolderOnly($File)
{
	return substr($File, 0, strrpos($File, "/"));
}
//----------------------------------------------------------------------------
function MainFolderOnly($File)
{
	return trim(substr($File, strrpos($File, "/")), "/");
}
//----------------------------------------------------------------------------
function SetUpGroups($Files)
{
	$narray  = array();
	$narray2 = array();
	
	foreach($Files as $key => $val)
		$narray[] = FolderOnly($val);
	
	$narray = array_unique($narray);
	sort($narray); // root folders only
	
	foreach($narray as $key => $val)
	{
		foreach($Files as $key2 => $val2)
		{
			if(contains($val."/", $val2))// change to end off string match only
				$narray2[$val][] = $val2;
		}
	}
	
	return($narray2);
}

function samefolder($sub, $main)
{
	
}
//----------------------------------------------------------------------------
?>