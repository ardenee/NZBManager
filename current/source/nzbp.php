<?
include("db.php");

class nzb
{		
	var $Settings;
	var $nzbpiece;	
	var $pars;
	var $sample;
	var $nfo;
	var $sfv;
	var $ssr;		
	var $db;
	var $srcstring;	
	var $Nzb;
	var $subs;
	
	function __construct() 
	{
	   nzb::GetSettings();
	   $this->nzbpiece  = 0;
	   $this->db        = false;
	   $this->srcstring = "";
	}
	
	function GetSettings()
	{
		$result = mysql_query("SELECT * FROM settings LIMIT 1;", DBLINK);
		
		if(@mysql_num_rows($result)>0)
		{
			$this->Settings = mysql_fetch_row($result); 
							
			if($this->Settings[8]!=0) // cleardb?
			{			
				nzb::ClearDB();
				mysql_query("UPDATE settings SET cleardb=0;", DBLINK);// reset					
			}
		}
		else
			nzb::DBSetup();
			
		@mkdir(rtrim($this->Settings[3], '/'), 0777, true); 
		@mkdir(rtrim($this->Settings[4], '/'), 0777, true);  
		@mkdir(rtrim($this->Settings[7], '/'), 0777, true); 	
		@mkdir(rtrim($this->Settings[9], '/'), 0777, true);		
	}	
	//-------------------------------------------------------------------------------
	function _ProcesslsStr($str) 
	{	
		$ret = "";
		
		if(strlen($str)>0)
		{	
			if(strripos($str, ':')==(strlen($str)-1))
				return substr($str, 0, -1); 
			
			$n     = strpos($str, ':');
			$str1  = trim(substr($str, 0, $n));
			$str2  = trim(substr($str, $n)); 
			$tmp   = $str1;
			$catch = 0;
			
			while($catch++<4)
				$tmp = trim(substr($tmp, 0, (strripos($tmp, ' '))));	
			
			$size     = trim(substr($tmp, strripos($tmp, ' ')));		
			$datetime = trim(substr($str1, strlen($tmp)).substr($str2, 0, 11)); 
			$datetime = trim(substr($datetime, strpos($datetime, ' ')));
			$filename = trim(substr($str2, 11)); 
			$ext      = "";
			
			if(strripos($filename, '.')>0)	
				$ext      = substr($filename, strripos($filename, '.')+1);		
				
			if(strlen($ext)>0)				
				$ret = $size."\t".strtotime($datetime)."\t".$filename."\t".$ext;
			else
				$ret = $size."\t".strtotime($datetime)."\t".$filename;
		}

		return $ret; 
	}		
	//----------------------------------------------------------------------------
	function GetFiles($Folder, $Types, $Recursive)
	{	
		$filelist = $this->SearchDirs($Types, rtrim($Folder, '/'), $Recursive);	
		sort($filelist);

		return $filelist;
	}
	//----------------------------------------------------------------------------	
	function SearchDirsOnly($Folder, $Recursive)
	{   
		$lscmd  = ($Recursive)?"ls -lRe '".$Folder."'":"ls -le '".$Folder."'";		
		$output = shell_exec($lscmd);
		$fnd    = array();

		if($output)
		{
			$dirlist = explode("\n", $output);
			unset($output); 	
			$dirlist = array_filter($dirlist);// remove empty elements
		}		
		
		if(count($dirlist)>0)
		{
			foreach($dirlist as $key => $str)
			{
				$l = explode("\t", rtrim($this->_ProcesslsStr($str), '/'));	
				
				if(is_dir($Folder."/".$l[2]) and contains("@eaDir", $l[2])==false and strlen($l[2])>0)// recycle bin check also?
					$fnd[] = $Folder."/".$l[2];
			}
		}

		return $fnd;
	} 
	//----------------------------------------------------------------------------	
	function GetFolders($Folder)
	{	
		$dirlist = $this->SearchDirsOnly(rtrim($Folder, '/'), false);	
		sort($dirlist);

		return $dirlist;
	}	
	//----------------------------------------------------------------------------
	function GetDBFiles()
	{	
		$result = mysql_query("SELECT * FROM hashes ORDER BY id;", DBLINK);
		$data   = array();

		if(@mysql_num_rows($result)>0)				
			while($d = mysql_fetch_row($result))
				$data[] = $d;	
		
		return $data;
	}
	//----------------------------------------------------------------------------
	function GetAllFiles($Folder, $Types)
	{	
		$filelist = $this->SearchDirs($Types, rtrim($Folder, '/'), true);	
		sort($filelist);

		return $filelist;
	}
	//----------------------------------------------------------------------------
	function SearchDirs($Types, $Folder, $Recursive)
	{   
		if(isset($Types))
			$Types = array_map('strtolower', $Types);
			
		$lscmd  = ($Recursive)?"ls -lRe '".$Folder."'":"ls -le '".$Folder."'";	
		$output = shell_exec($lscmd);
		$fnd    = array();	

		if($output)
		{
			$filelist = explode("\n", $output);
			unset($output); 
			$filelist = array_filter($filelist);// remove empty elements		
		}
		
		foreach($filelist as $key=>$str)
		{
			$l = explode("\t", rtrim($this->_ProcesslsStr($str), '/'));	

			if(count($l)==1 and strlen($l[0])>0) // folder
				$Folder = $l[0];
			else
			{		
				if(contains("@", $l[2])==false and contains("Thumbs.db", $l[2])==false )// recycle bin check also?
				{			
					if(count($Types)>0 and is_file($Folder."/".$l[2]))
					{
						if(in_array(strtolower($l[3]), $Types))
							$fnd[] = $Folder."/".$l[2];
					}
					elseif(is_file($Folder."/".$l[2]))
						$fnd[] = $Folder."/".$l[2];	
				}					
			}
		}

		return $fnd;
	} 
//----------------------------------------------------------------------------
	function _contains($substring, $string) 
	{
		$pos = stripos($string, $substring);
	 
		if($pos === false)
			return false;
		else  
			return true; 
	}
	//----------------------------------------------------------------------------
	function DBSetup()
	{
		mysql_query("TRUNCATE TABLE settings;", DBLINK); 
			   
		$st = "CREATE TABLE IF NOT EXISTS `settings` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `api` varchar(32) NOT NULL,
		  `autorename` tinyint(1) NOT NULL,
		  `tmpFolder` varchar(255) NOT NULL,
		  `saveToFolder` varchar(255) NOT NULL,
		  `MoveFolders` text NOT NULL,
		  `nzbSave` tinyint(1) NOT NULL,
		  `nzbScanFolder` varchar(255) NOT NULL,
		  `cleardb` tinyint(1) NOT NULL,
		  `par2ScanFolder` varchar(255) NOT NULL,
		  `unpack` tinyint(1) NOT NULL,
		  `keeppar` tinyint(1) NOT NULL,
		  `segments` tinyint(1) NOT NULL,
		  `nameonly` tinyint(1) NOT NULL,	
		  `filters` text NOT NULL,		  
		  PRIMARY KEY (`id`)
		) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=3;";
		mysql_query($st, DBLINK); 

		$st = "CREATE TABLE IF NOT EXISTS `hashes` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `filename` varchar(255) NOT NULL,
		  `hash` varchar(32) NOT NULL,
		  `date` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE CURRENT_TIMESTAMP,
		  `title` varchar(255) NOT NULL,
		  PRIMARY KEY (`id`)
		) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";
		mysql_query($st, DBLINK); 					   
					   
		$st = "CREATE TABLE IF NOT EXISTS `files` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `hashid` int(11) NOT NULL,
		  `poster` text NOT NULL,
		  `date` varchar(12) NOT NULL,
		  `subject` text NOT NULL,
		  `groups` text NOT NULL,
		  PRIMARY KEY (`id`)
		) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";
		mysql_query($st, DBLINK); 

		$st = "CREATE TABLE IF NOT EXISTS `meta` (
		  `id` int(11) NOT NULL AUTO_INCREMENT,
		  `hashid` int(11) NOT NULL,
		  `type` text NOT NULL,
		  `value` text NOT NULL,
		  PRIMARY KEY (`id`)
		) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";
		mysql_query($st, DBLINK); 

		$st = "CREATE TABLE IF NOT EXISTS `segments` (
			  `id` int(11) NOT NULL AUTO_INCREMENT,
			  `fileid` int(11) NOT NULL,
			  `hashid` int(11) NOT NULL,
			  `bytes` int(11) NOT NULL,
			  `number` int(11) NOT NULL,
			  `value` text NOT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;";
		mysql_query($st, DBLINK); 
				
		$this->Settings[] = 1;
		$this->Settings[] = "Not Set";
		$this->Settings[] = 0;
		$this->Settings[] = "/volume1/Data/nzb/tmp/";
		$this->Settings[] = "/volume1/Data/nzb/saved/";
		$this->Settings[] = "/volume1/Data/";
		$this->Settings[] = 0;
		$this->Settings[] = "/volume1/Data/nzb/scan/";
		$this->Settings[] = 0;
		$this->Settings[] = "/volume1/Data/DownloadStation/";
		$this->Settings[] = 0;
		$this->Settings[] = 0;
		$this->Settings[] = 0;
		$this->Settings[] = 0;
		$this->Settings[] = "HDTV:x264:2hd:720p:1080p:WEB:DL:DD5:H264:iT00NZ:ASAP:LOL:CRiMSON:2012:NGB:264:RiVER:DVDRip:-:SPARKS:BDRip:NOSCREENS:REPACK:mp4";
		
		$result = mysql_query("INSERT INTO settings (id, api, autorename, tmpFolder, saveToFolder, MoveFolders, nzbSave, nzbScanFolder, cleardb, par2ScanFolder, unpack, keeppar, segments, nameonly, filters) VALUES(NULL, 
								'".$this->Settings[1]."', 
								".$this->Settings[2].", 
								'".$this->Settings[3]."', 
								'".$this->Settings[4]."', 
								'".$this->Settings[5]."', 
								 ".$this->Settings[6].", 
								'".$this->Settings[7]."', 
								 ".$this->Settings[8].", 
								'".$this->Settings[9]."', 
								 ".$this->Settings[10].",
								 ".$this->Settings[11].",
								 ".$this->Settings[12].",
								 ".$this->Settings[13].",
								'".$this->Settings[14]."');", DBLINK);									
	}
	//---------------------------------------------------------------------------- 
	function GetFileCount()
	{	
		return $this->nzbpiece+1;
	}
	//----------------------------------------------------------------------------
	function ClearDB()
	{
		$this->Settings[8]=0;
		mysql_query("TRUNCATE TABLE hashes;",   DBLINK); 	
		mysql_query("TRUNCATE TABLE files;",    DBLINK); 
		mysql_query("TRUNCATE TABLE meta;",     DBLINK); 
		mysql_query("TRUNCATE TABLE segments;", DBLINK);
		array_map('unlink', glob($this->Settings[3]."*"));	// clear tmp folder		
	}
	//----------------------------------------------------------------------------			
	function ShowFile($nzbfile, $hash) 
	{
		$fhash    = $nzbfile;			
		$this->db = false;	
		$XML      = array();
		
		if(strlen($hash)==0)
		{	
			$nzbfile     = $this->GetFile($nzbfile);
			$XML["HASH"] = substr($nzbfile, 0, strpos($nzbfile, "."));				
			$this->ProcessFile($this->Settings[3].$nzbfile);
		}
		else
			$XML["HASH"] = $hash;
	
		$h_id        = -1;		
		$result      = mysql_query("SELECT id, hash FROM hashes WHERE hash='".$XML["HASH"]."';", DBLINK);			
		$h_id        = mysql_fetch_row($result);		
		$result      = mysql_query("SELECT * FROM meta WHERE hashid=".$h_id[0].";", DBLINK);
			
		if(mysql_num_rows($result)>0)
		{
			while($m_data = mysql_fetch_row($result))
				$XML["META"][$m_data[2]] = $m_data[3];	
		}		
					
		$result = mysql_query("SELECT * FROM files WHERE hashid=".$h_id[0]." ORDER BY date;", DBLINK);	

		while($f_data = mysql_fetch_row($result))
		{
			$XML["FILES"][$f_data[4]]["id"]       = $f_data[0];
			$XML["FILES"][$f_data[4]]["poster"]   = $f_data[2];
			$XML["FILES"][$f_data[4]]["date"]     = $f_data[3];
			$XML["FILES"][$f_data[4]]["groups"]   = $f_data[5];
			$XML["FILES"][$f_data[4]]["segments"] = $f_data[4];
	
			if($this->Settings[12]==1)
			{			
				$result2 = mysql_query("SELECT * FROM segments WHERE fileid=".$f_data[0]." ORDER BY number;", DBLINK);
				
				while($s_data = mysql_fetch_row($result2))
				{
					$XML["SEGMENTS"][$f_data[4]][$s_data[4]]["bytes"] = $s_data[3];
					$XML["SEGMENTS"][$f_data[4]][$s_data[4]]["value"] = $s_data[5];
				}
					
				$XML["FILES"][$f_data[4]]["segments"] = count($XML["SEGMENTS"][$f_data[4]]);
			}
			else
			{
				$result2 = mysql_query("SELECT COUNT(*) FROM segments WHERE fileid=".$f_data[0].";", DBLINK);
				$s_data  = mysql_fetch_row($result2);
				$XML["FILES"][$f_data[4]]["segments"] = $s_data[0];
			}
		}		
		
		return $XML;
	}
	//----------------------------------------------------------------------------
	function StripName($name)
	{
		$nname = $name;
		
		if( $this->Settings[13]==1)
		{
			$nname = substr($name, (strpos($name, "\"")+1));
			$nname = substr($nname, 0, strrpos($nname, "\""));		
		}	
	
		return $nname;	
	}
	//----------------------------------------------------------------------------
	function GetFile($nzblink) 
	{
		$path_parts = pathinfo($nzblink);		
		$nzblink    = urldecode($nzblink);
		
		if($path_parts['dirname']."/"==$this->Settings[4] or $path_parts['dirname']."/"==$this->Settings[7]) 	
		{ 
			$this->db  = false;
			$nzbsource = file_get_contents($nzblink); 
			$file      = md5($nzbsource);					
			file_put_contents($this->Settings[3].$file.".nzb", $nzbsource);
			
			if($path_parts['dirname']."/"==$this->Settings[7])	
				unlink($nzblink); 			
			
			return $file.".nzb";		
		}
		else		
			header("Location: index.php?msg=File Source Invalid - ".$path_parts['dirname']);
	}
	//----------------------------------------------------------------------------
	function ProcessFile($nzbfile) 
	{	
		$FileMD5  = md5_file($nzbfile); 
		$filename = basename($nzbfile);	
		$result   = mysql_query("SELECT * FROM hashes WHERE hash='$FileMD5';", DBLINK);
		
		if(mysql_num_rows($result)>0)
			return; // already in DB, return dont re-enter		
		
		$filecontent = file_get_contents($nzbfile);
		$xml         = simplexml_load_string($filecontent, "SimpleXMLElement", LIBXML_PARSEHUGE) or die ("Unable to load NZB file!"); // load error page instead
		$h_id 	     = -1;	

		if(!$this->db) // not working with db?
			mysql_query("INSERT INTO hashes (id, filename, hash, date, title) VALUES (null, \"".addslashes($filename)."\", \"$FileMD5\", NULL, \"".addslashes($this->srcstring)."\");", DBLINK);	
		
		$result = mysql_query("SELECT id FROM hashes WHERE hash=\"$FileMD5\";", DBLINK); //get id of last entry
		$h_id   = mysql_fetch_row($result);	// get correct id!		
		
		if(isset($xml->head->meta) && !$this->db)
		{
			foreach($xml->head->meta as $key)
				mysql_query("INSERT INTO meta (id, hashid, type, value) VALUES (null, ".$h_id[0].", '".addslashes($key["type"])."', '".addslashes($key[0])."');", DBLINK);
		}		

		foreach($xml->{"file"} as $nfile)
		{		
			$nsubject = trim($nfile['subject']);
			$ndate    = trim($nfile['date']);
			$nposter  = trim($nfile['poster']); 
			$groups   = array();
			
			foreach($nfile->groups->group as $group) 
				$groups[] = trim($group);

			$ngroups = implode(",", $groups);		
			
			if(!$this->db)
				mysql_query("INSERT INTO files (id, hashid, poster, date, subject, groups) VALUES (null, ".$h_id[0].", '".addslashes($nposter)."', '$ndate', '".addslashes($nsubject)."', '".addslashes($ngroups)."');", DBLINK);						

			$f_id    = -1;
			$result3 = mysql_query("SELECT id FROM files ORDER BY id DESC LIMIT 0,1;", DBLINK); // get last entry id	
			$f_id    = mysql_fetch_row($result3);									
			
			foreach($nfile->segments->segment as $segment)
			{
				$nbytes  = trim($segment['bytes']); 
				$nnumber = trim($segment['number']);
				$nvalue  = trim($segment[0]);
				
				if(!$this->db)
					mysql_query("INSERT INTO segments (id, fileid, hashid, bytes, number, value) VALUES (null, ".$f_id[0].", ".addslashes($h_id[0]).", $nbytes, $nnumber, \"$nvalue\");");									
			}
		}	

		@unlink($nzbfile);
		
		return $FileMD5;
	}	
}
//----------------------------------------------------------------------------
?>