<?php
require("nzbp.php");
$nzb          = new nzb;
$searchstring = "Not Set";
$cat          = 0;

if(isset($_POST["searchstring"]))
	$searchstring = $_POST["searchstring"];
	
$searchstr = urlencode($searchstring);
$searchurl = "https://www.nzbplanet.net/api?t=search&q=$searchstr&apikey=".$nzb->Settings[1]."&extended=1";
$nzbsearch = file_get_contents($searchurl); 

echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>NZB</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
echo " </head>\n";

echo "<table height=100%>\n";
echo "  <tr class=title1 id=ignore>\n";
echo "   <td colspan=2>NZB Planet Search Results</td>\n";
echo "  </tr>\n";	
echo "  <form action=nzbsearch.php method=post>\n";	
echo "  <tr class=main1 id=ignore>\n";
echo "   <td>Search for:</td>\n";
echo "   <td><input type=text name=\"searchstring\" value=\"$searchstring\" size=50 max=50>&nbsp;<input type=\"submit\" value=Submit></td>\n";
echo "  </tr>\n";	
echo "  </form>\n";


echo " <tr class=main1>\n";
echo "  <td colspan=2>\n";

ProcessSearch($nzbsearch);

echo "  </td>\n";
echo " </tr>\n";
echo " <tr class=main1 id=ignore>\n";
echo "  <td height=100% colspan=2>&nbsp;</td>\n";
echo " </tr>\n";
echo $footer;
echo "</table>\n";

//-------------------------------------------------------------------------------------------------

function ProcessSearch($filecontent) 
{	
	global $nzb;
	$num     = 1;
	$xml     = simplexml_load_string($filecontent, "SimpleXMLElement", LIBXML_PARSEHUGE) or die ("Unable to load XML data!"); 	
	$addData = additionalxml($filecontent);	
	//file_put_contents($nzb->Settings[9]."raw.txt", $filecontent);	
	//file_put_contents($nzb->Settings[9]."raw2.txt", $addData[0]);	
	
	echo "<table width=100% onMouseover=\"changeto(event, '#c4c4c4')\" onMouseout=\"changeback(event, '#E9E9E9')\">\n";
	echo " <tr class=title1 id=ignore>\n";
	echo "  <td><b>Num</b></td>\n";
	echo "  <td><b>Title</b></td>\n";
	echo "  <td><b>Category</b></td>\n";
	echo "  <td><b>Grabs</b></td>\n";
	echo "  <td><b>Files</b></td>\n";
	echo "  <td><b>Comments</b></td>\n";
	echo "  <td><b>Size</b></td>\n";
	echo "  <td><b>Date</b></td>\n";
	echo "  <td><b>Action</b></td>\n";
	echo " </tr>\n";
	
	foreach($xml->channel->item as $nfile)
	{		
		$ntitle = trim($nfile->title);
		$ndate  = trim($nfile->pubDate);
		$nlink  = trim($nfile->link); 
		$nguid  = trim($nfile->guid);
		$ncat   = trim($nfile->category);
		$natts  = $nfile->enclosure->attributes();		
		
		//$searchurl2 = "https://www.nzbplanet.net/api?t=details&id=".$addData[substr($nguid, strripos($nguid, "/")+1)][3];
		//$nzbsearch2 = file_get_contents($searchurl2); 
		//file_put_contents($nzb->Settings[9].$nguid.".txt", $nzbsearch2);	

		//echo 	"https://www.nzbplanet.net/api?t=details&id=".$addData[substr($nguid, strripos($nguid, "/")+1)][3]."<BR>\n";	

		echo " <tr class=main1>\n";
		echo "  <td>$num</td>\n";
		echo "  <td><a href=\"$nguid\">$ntitle</a></td>\n";
		echo "  <td>$ncat</td>\n";
		echo "  <td>".$addData[substr($nguid, strripos($nguid, "/")+1)][0]."</td>\n";
		echo "  <td>".$addData[substr($nguid, strripos($nguid, "/")+1)][1]."</td>\n";
		echo "  <td>".$addData[substr($nguid, strripos($nguid, "/")+1)][2]."</td>\n";
		echo "  <td>".format_bytes(trim($natts["length"]))."</td>\n";
		echo "  <td>$ndate</td>\n";
		echo "  <td><a href=\"downloadfile.php?srcstring=".$ntitle."&folder=".$nzb->Settings[7]."&link=".urlencode($nlink)."\">Download</a></td>\n";
		echo " </tr>\n";		
		$num++;
	}		
	
	echo "</table>\n";
}	
//-------------------------------------------------------------------------------------------------
function additionalxml($xml)
{
	$reader   = new XMLReader;
	$reader->xml($xml);
	$lguid    = -1;
	$grabs    = -1;
	$files    = -1;
	$comments = -1;
	$guid 	  = -1;
	$DATA     = array();

	while($reader->read()) 
	{
		if($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'newznab:attr') 
		{	
			if($reader->getAttributeNo(0)=="guid")
				$guid = $reader->getAttributeNo(1);
				
			if($guid!=$lguid)
			{	
				$grabs    = -1;
				$files    = -1;
				$comments = -1;
			}
			
			if($reader->getAttributeNo(0)=="files")
				$files    = $reader->getAttributeNo(1);			
			if($reader->getAttributeNo(0)=="grabs")
				$grabs    = $reader->getAttributeNo(1);
			if($reader->getAttributeNo(0)=="comments")
				$comments = $reader->getAttributeNo(1);	
			
			if($files!="-1" and $grabs!="-1" and $comments!="-1")
			{
				$DATA[$guid] = array($grabs,$files,$comments,$guid);
				$grabs    = -1;
				$files    = -1;
				$comments = -1;
			}
				
			$lguid = $guid;
		}
	}
	
	$reader->close();
	
	return $DATA;
}
//-------------------------------------------------------------------------------------------------
function DisplayError($msg)
{
	header("Location: index.php?msg=$msg");
	Exit;
}
//-------------------------------------------------------------------------------
 function format_bytes($a_bytes)
{
    if ($a_bytes < 1024) {
        return $a_bytes .' B';
    } elseif ($a_bytes < 1048576) {
        return round($a_bytes / 1024, 2) .' KiB';
    } elseif ($a_bytes < 1073741824) {
        return round($a_bytes / 1048576, 2) . ' MiB';
    } elseif ($a_bytes < 1099511627776) {
        return round($a_bytes / 1073741824, 2) . ' GiB';
    } elseif ($a_bytes < 1125899906842624) {
        return round($a_bytes / 1099511627776, 2) .' TiB';
    } elseif ($a_bytes < 1152921504606846976) {
        return round($a_bytes / 1125899906842624, 2) .' PiB';
    } elseif ($a_bytes < 1180591620717411303424) {
        return round($a_bytes / 1152921504606846976, 2) .' EiB';
    } elseif ($a_bytes < 1208925819614629174706176) {
        return round($a_bytes / 1180591620717411303424, 2) .' ZiB';
    } else {
        return round($a_bytes / 1208925819614629174706176, 2) .' YiB';
    }
} 
//-------------------------------------------------------------------------------------------------
?>