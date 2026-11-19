<?php
//-------------------------------------------------------------------------------------------------
function DisplayFile($NZB, $Link, $Hash)
{
	$XML = $NZB->ShowFile($Link, $Hash);
	OutputFile($NZB, $XML);
}
//-------------------------------------------------------------------------------------------------
function OutputFile($NZB, $XML)
{
	if(isset($XML["META"]))
	{
		echo "    <table onMouseover=\"changeto(event, '#c4c4c4')\" onMouseout=\"changeback(event, '#E9E9E9')\">\n";
		echo "     <tr class=title1 id=ignore>\n";
		echo "      <td>(Head Meta) Attributes</td>\n";
		echo "      <td>(Head Meta) Body</td>\n";
		echo "     </tr>\n"; 
		
		foreach($XML["META"] as $key => $val)
		{
			echo "     <tr class=main1>\n";
			echo "      <td>".$key."</td>\n";
			echo "      <td>".$val."</td>\n";
			echo "     </tr>\n";
		}
		
		echo "    </table>\n";
		echo "   </td>\n";
		echo "  </tr>\n";
		echo "  <tr class=main1>\n";
		echo "   <td colspan=2>\n";
	}
	
	if(isset($XML["FILES"]))
	{		
		echo "<table height=100% onMouseover=\"changeto(event, '#c4c4c4')\" onMouseout=\"changeback(event, '#E9E9E9')\">\n";
		echo " <form name=nzb id=nzbform action=submit.php method=post>\n";
		echo "  <input type=\"hidden\" name=\"hash\"      value=\"".$XML['HASH']."\">\n";
		echo "  <input type=\"hidden\" name=\"srcstring\" value=\"".$NZB->srcstring."\">\n";
		echo " <tr class=title1 id=ignore>\n";
		echo "  <td>Num</td>\n";
		echo "  <td>Subject</td>\n";
		echo "  <td class=right>Groups</td>\n";
		echo "  <td class=right>Size (KB)</td>\n";
		echo "  <td class=right>Parts</td>\n";
		echo "  <td class=right>Poster</td>\n";
		echo "  <td class=right>Date</td>\n";
		echo " </tr>\n";		
		
		$NZB->nzbpiece = 0;
		$nzbsize       = 0;
		$nzbsegcount   = 0;
		
		//echo "<pre>";
		//print_r($XML["FILES"]);
		//echo "</pre>";
		
		
		foreach($XML["FILES"] as $key => $val)
		{			
			$size = GetFileSegTotalSize($val['id'], $XML["SEGMENTS"][$key], $nzbsegcount);
			
			echo " <tr class=main1>\n";
			echo "	<td>".($NZB->nzbpiece+1)."<input type=checkbox name=".$val['id']." CHECKED></td>\n";
			echo "  <td class=bold><a onclick=\"displayRow('r".($NZB->nzbpiece+1)."')\" style=\"cursor:hand; cursor:pointer\"><B>".$NZB->StripName($key)."</B></a></td>\n";
			echo "  <td class=right>".$val['groups']."</td>\n";
			echo "  <td class=right>".number_format(($size/1024), 2)."</td>\n";
			echo "  <td class=right>".$val['segments']."</td>\n";
			echo "  <td>".htmlspecialchars($val['poster'])."</td>\n";
			echo "  <td class=right>".date('d-M-Y H:i:s', $val['date'])."</td>\n";
			echo " </tr>\n".$data." </tr>\n";			
			
			$nzbsize+=$size;
			
			if($NZB->Settings[12]==1) // display segments
			{
				echo " <tbody id='r".($NZB->nzbpiece+1)."' name='r".($NZB->nzbpiece+1)."'>\n";			
				echo "  <tr class=title2 id=ignore>\n";
				echo "   <td>Num</td>\n";
				echo "   <td>Message ID</td>\n";	
				echo "   <td class=right>Number</td>\n";	
				echo "   <td class=right>Bytes</td>\n";			
				echo "   <td class=right>File</td>\n";			
				echo "   <td>&nbsp;</td>\n";	
				echo "   <td>&nbsp;</td>\n";					
				echo "  </tr>\n";
					
				foreach($XML["SEGMENTS"][$key] as $key2 => $val2)
				{
					echo "   <tr class=main1small>\n";
					echo "    <td>".$key2."</td>\n";	
					echo "    <td>".$val2['value']."</td>\n";	
					echo "    <td class=right>".$key2."</td>\n";	
					echo "    <td class=right>".number_format($val2['bytes'])."</td>\n";		
					echo "    <td class=right>".($NZB->nzbpiece+1)."</td>\n";			
					echo "    <td>&nbsp;</td>\n";	
					echo "    <td>&nbsp;</td>\n";					
					echo "   </tr>\n";									
				}
				
				$nzbsegcount+=count($XML["SEGMENTS"][$key]);
				
				echo "  </tbody>\n";

			}
				
			if($NZB->Settings[11])
			{		
				if(stripos($key, ".par2")>0 and contains(".vol", $key)==true and contains("+", $key)==true) 
					$NZB->pars[] = $val['id'];
			}
			else if(stripos($key, ".par2")>0) 
					$NZB->pars[] = $val['id'];
					
			if(stripos($key, ".nfo")>0) 
				$NZB->nfo[] = $val['id'];
					
			if(stripos($key, ".sfv")>0)
				$NZB->sfv[] = $val['id'];
					
			if(stripos($key, ".srr")>0)
				$NZB->srr[] = $val['id'];
					
			if(stripos($key, "sample"))
				$NZB->sample[] = $val['id'];	

			if(stripos($key, "nzb"))
				$NZB->Nzb[] = $val['id'];	
					
			if(stripos($key, "subpack") or stripos($key, "subs"))
				$NZB->subs[] = $val['id'];	
					
			$NZB->nzbpiece++;			
		}		
		
		echo " <tr class=title2 id=ignore>\n";
		echo"   <td colspan=3>Options</td>\n";
		echo"   <td class=right>Size</td>\n";
		echo"   <td class=right>Count</td>\n";
		echo"   <td colspan=2>&nbsp;</td>\n";
		echo"  </tr>\n";
		
		
		echo " <tr class=main1 id=ignore>\n";
		echo "	<td>&nbsp;</td>\n";
		echo "  <td><input type=button name='checkall' value=Invert onclick=\"checkedAll('nzbform');\" checked> - ";
			
		if(count($NZB->pars)>0)
			echo "<input type=checkbox name='par' id='par' value=par onclick='checkedBoxes(\"par\");' checked> Pars ";
		if(count($NZB->sfv)>0)
			echo "<input type=checkbox name='sfv' id='sfv' value=sfv onclick='checkedBoxes(\"sfv\");' checked> SFV's ";
		if(count($NZB->sample)>0)
			echo "<input type=checkbox name='sample' id='sample' value=sample onclick='checkedBoxes(\"sample\");' checked> Sample's ";
		if(count($NZB->srr)>0)
			echo "<input type=checkbox name='srr' id='srr' value=srr onclick='checkedBoxes(\"srr\");' checked> SRR's ";
		if(count($NZB->nfo)>0)
			echo "<input type=checkbox name='nfo' id='nfo' value=nfo onclick='checkedBoxes(\"nfo\");' checked> NFO's ";	
		if(count($NZB->Nzb)>0)
			echo "<input type=checkbox name='nzb' id='nzb' value=nzb onclick='checkedBoxes(\"nzb\");' checked> NZB's ";	
		if(count($NZB->subs)>0)
			echo "<input type=checkbox name='subs' id='subs' value=subs onclick='checkedBoxes(\"subs\");' checked> Subs's ";
			
		echo "   <input type=\"submit\" value=\"Submit\"></td>\n";
		echo "	<td>&nbsp</td>\n";
		echo "  <td class=right>".number_format((($nzbsize/1024)/1024), 2)." MB</td>\n";
		echo "  <td class=right>".number_format($nzbsegcount)."</td>\n";
		echo "  <td>&nbsp</td>\n";
		echo "  <td>&nbsp</td>\n";
		echo " </tr>\n";
		echo "</form>\n";		
		echo "</table>\n";
	}
}
//-------------------------------------------------------------------------------------------------
function GetFileSegTotalSize($fileid, $filesegs, &$nzbsegcount)
{
	$ret = 0;
	
	if(count($filesegs)>0)
	{	
		foreach($filesegs as $key => $val)
			$ret+=$val['bytes'];
	}
	else
	{
		$result = mysql_query("SELECT SUM(bytes), count(bytes) FROM segments WHERE fileid=$fileid;", DBLINK);
		$value  = mysql_fetch_row($result);
		$ret    = $value[0];
		$nzbsegcount+= $value[1];
	}
	
	return $ret;
}
//-------------------------------------------------------------------------------------------------
?>