<?php
	$from   = "index.php";
	$folder = "";

	if(isset($_GET["from"]))
		$from = $_GET["from"];

	if(isset($_GET["folder"]))
		$folder = $_GET["folder"];		
		
	if(is_dir($folder))
	{			
		shell_exec("rm -rf '$folder/@eaDir'"); // delete files in synology hidden folder
		rmdir($folder."/@eaDir");              // delete synology hidden folder
		shell_exec("rm -rf '$folder/'");       // delete files in synology hidden folder
		rmdir($folder);
	}	
	
	header("Location: $from");
?>