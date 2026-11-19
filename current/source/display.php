<?require("nzbp.php");require("display.inc");$nzb  = new nzb;$link = "";$hash = "";if(isset($_GET["link"]))	$link = $_GET["link"];if(isset($_GET["hash"]))	$hash = $_GET["hash"];	if(isset($_GET["srcstring"]))	$nzb->srcstring = $_GET["srcstring"];		echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>NZB - Display</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";echo "  <SCRIPT LANGUAGE=\"JavaScript\" TYPE=\"text/javascript\" SRC=\"jsstuff.js\"></SCRIPT>\n";
echo " </head>\n";echo "<body onload=\"deSelDef()\">\n"; echo " <table height=100%>\n";echo " <tr class=main1 valign=top>\n";echo "  <td colspan=2>\n";DisplayFile($nzb, $link, $hash);echo "  </td>\n";echo " </tr>\n";echo " <tr class=main1 id=ignore height=100%>\n";echo "  <td colspan=2>&nbsp;</td>\n";echo " </tr>\n";echo $footer;	echo "</table>\n";if(count($nzb->pars)>0)	$str1 = implode('", "', $nzb->pars);if(count($nzb->nfo)>0)	$str2 = implode('", "', $nzb->nfo);if(count($nzb->sfv)>0)	$str3 = implode('", "', $nzb->sfv);if(count($nzb->srr)>0)	$str4 = implode('", "', $nzb->srr);if(count($nzb->sample)>0)	$str5 = implode('", "', $nzb->sample);if(count($nzb->Nzb)>0)	$str6 = implode('", "', $nzb->Nzb);	if(count($nzb->subs)>0)	$str7 = implode('", "', $nzb->subs);	echo "<script language='JavaScript'>\n"; echo "function checkedBoxes(type) {\n"; echo "	if(type==='par')\n";echo "    var parsarray = [\"".$str1."\"];\n"; 	echo "	if(type==='nfo')\n";echo "    var parsarray = [\"".$str2."\"];\n"; 	echo "	if(type==='sfv')\n";echo "    var parsarray = [\"".$str3."\"];\n"; 	echo "	if(type==='srr')\n";echo "    var parsarray = [\"".$str4."\"];\n"; 	echo "	if(type==='sample')\n";echo "    var parsarray = [\"".$str5."\"];\n"; echo "	if(type==='nzb')\n";echo "    var parsarray = [\"".$str6."\"];\n"; 	echo "	if(type==='subs')\n";echo "    var parsarray = [\"".$str7."\"];\n"; echo "  checkedboxes(parsarray, type);\n";echo "}\n\n";	echo "function checkedboxes(parsarray, type) {\n"; echo "    \"use strict\";\n"; echo "    var i;\n";echo "    for (i = 0; i < document.getElementById('nzbform').elements.length; i++) {\n"; echo "        if (parsarray.indexOf(document.getElementById('nzbform').elements[i].name) !== -1) {\n"; echo "                document.getElementById('nzbform').elements[i].checked = document.getElementById('nzbform').elements[type].checked;\n";echo "        }\n"; echo "    }\n"; echo "}\n"; echo "function deSelDef()\n";echo "{\n";if($nzb->Settings[12]==1)  echo "  displayAll();\n";if(count($nzb->pars)>0)  echo "  document.getElementById('par').click();\n";if(count($nzb->nfo)>0)  echo "  document.getElementById('nfo').click();\n";if(count($nzb->sfv)>0)  echo "  document.getElementById('sfv').click();\n";if(count($nzb->srr)>0)  echo "  document.getElementById('srr').click();\n";if(count($nzb->sample)>0)  echo "  document.getElementById('sample').click();\n";if(count($nzb->Nzb)>0)  echo "  document.getElementById('nzb').click();\n";if(count($nzb->subs)>0)  echo "  document.getElementById('subs').click();\n"; echo "}\n";    
echo "function displayAll()\n";
echo "{\n";  
echo "  var i=1;\n";
echo "  var row;\n";
echo "  for(i=1;i<".$nzb->GetFileCount().";i++)\n";
echo "  {\n";
echo "    row = document.getElementById('r'+i);\n";  
echo "    if (row.style.display == '')\n";   
echo "      row.style.display = 'none';\n"; 	
echo "    else\n";  
echo "      row.style.display = '';\n";   
echo "  }\n";
echo "}\n"; 
echo "</script>\n"; ?>