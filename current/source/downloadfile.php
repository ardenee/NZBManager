<?php
	if(isset($_GET["link"]))
		$link = $_GET["link"];
		
	if(isset($_GET["folder"]))
		$folder = $_GET["folder"];
		
	if(isset($_GET["srcstring"]))
		$srcstring = $_GET["srcstring"];
		
	$nzblink    = urldecode($link);
	$path_parts = pathinfo($nzblink);		

	if($path_parts['dirname']=="https://www.nzbplanet.net/getnzb") // load http file - array of providers check - future release.
	{
		$nzbsource = file_get_contents($nzblink); 				
		file_put_contents($folder.$srcstring.".nzb", $nzbsource);
	}
	
	header("Location: renamefile.php?oldname=".urlencode($folder.$srcstring.".nzb"));
?>