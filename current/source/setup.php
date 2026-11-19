<?php
echo "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.0 Transitional//EN\">\n";
echo "<html>\n";
echo " <head>\n";
echo "  <title>Dup Setup!</title>\n";
echo "  <META http-equiv=Content-Type content=\"text/html; charset=iso-8859-1\">\n";
echo "  <META HTTP-EQUIV=\"CACHE-CONTROL\" CONTENT=\"NO-CACHE\">\n";
echo "  <META HTTP-EQUIV=\"EXPIRES\" CONTENT=".Date("D, d M Y h:i:s T").">\n";//Mon, 22 Jul 2002 11:12:01 GMT,  must be GMT
echo "  <LINK href=\"style.css\" type=text/css rel=stylesheet>\n";
echo " </head>\n";
echo " <table width=100% cellspace=1 height=100%>\n";
echo "  <tr class=title1>\n"; 
echo "   <td colspan=2>Setup</td>\n";
echo "  </tr>\n";
echo "  <form name=\"setup\" action=setup.php method=post>\n";
echo "  <tr >\n";
echo "   <td>MySQL Password</td>\n";
echo "   <td><input type=password name=password size=40 max=255></td>\n";
echo "  </tr>\n";
echo "  <tr >\n";
echo "   <td>&nbsp;</td>\n";
echo "   <td><input type=\"submit\" value=\"Setup\"></td>\n";
echo "  </tr>\n";	
echo "  </form>\n";
echo "  <tr>\n";
echo "   <td colspan=2 height=100%>";
echo "   </td>\n";
echo "  </tr>\n";
echo "  <tr class=main1 id=\"ignore\">\n";
echo "   <td colspan=3><a href=\"http://www.arden.ie/nzb\" target=\"_blank\">www.arden.ie/nzb</a> - <a href=\"index.php\">Home</a> - <a href=\"par2.php\">PAR2</a> - <a href=\"unpacking.php\">Unpacking</a> - <a href=\"renaming.php\">Renaming</a> - <a href=\"merging.php\">Merging</a> - <a href=\"moving.php\">Moving</a></td>\n";
echo "  </tr>\n";	
echo " </table>";
echo "</html>";

$password = "";

if(isset($_POST["password"]))
	$password = $_POST["password"];	

if(strlen($password)>0)
{
	$dbf = file_get_contents("db.php");
	$da = explode("\n", $dbf);	
	
	$pwd = false;
		
	foreach($da as $key=>$val)
	{
		if(strpos($val, "dbpass = \"")>0 and strlen($password)>0 and !$pwd)
			$da[$key] = "$"."dbpass = \"LEGACY_NZB_DB_PASSWORD_REMOVED\";";
	}
		
	$dbf = implode($da, "\n");
	$dbf = str_replace("LEGACY_NZB_DB_PASSWORD_REMOVED", $password, $dbf);
	file_put_contents("db.php", $dbf);
		
	header('Location: index.php');	
}
?>