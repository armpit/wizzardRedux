<!-- 

Import an existing DAT into the system

Requires:
	filename	File name in the format of "Manufacturer - SystemName (Source .*)\.dat"
	
Note: Need to auto-rename files if the name in the set already exists (but not the same size/crc/md5/sha1):
	e.g. Agi.bin -> Agi (crc32).bin
-->

<?php

echo "<h2>Import From Datfile</h2>";

// First, get the pattern of the file name. This is required for organization.
$datpattern = "/^(\S+) - (\S+) \((\S+) .*\)\.dat$/";

$path_to_root = (getcwd() == "/wod/" ? "" : "..");

if (!$_GET["filename"])
{
	echo "<b>You must supply a filename as a URL parameter! (filename=xxx)</b><br/>";
	echo "<a href='".$path_to_root."/index.php'>Return to home</a>";
	
	die();
}
if (!file_exists($path_to_root."/temp/".$_GET["filename"]))
{
	echo "<b>The file you supply must be in ".$_SERVER["DOCUMENT_ROOT"]."/wod/temp/</b><br/>";
	echo "<a href='".$path_to_root."/index.php'>Return to home</a>";
	
	die();
}
if (!preg_match($datpattern, $_GET["filename"]))
{
	echo "<b>DAT not in the proper pattern! (Manufacturer - SystemName (Source .*)\.dat)</b><br/>";
	echo "<a href='".$path_to_root."/index.php'>Return to home</a>";
	
	die();
}

echo "<p>The file ".$_GET["filename"]." has a proper pattern!</p>";

// Next, get information from the database on the current machine
$fileinfo = explode(" - ", $_GET["filename"]);
$manufacturer = $fileinfo[0];
$fileinfo = explode(" (", $fileinfo[1]);
$system = $fileinfo[0];
$source = explode(" ", $fileinfo[1])[0];

$link = mysqli_connect('localhost', 'root', '', 'wod');
if (!$link)
{
	die('Error: Could not connect: ' . mysqli_error($link));
}

echo "Connection established!<br/>";

$query = "SELECT id
	FROM systems
	WHERE manufacturer='$manufacturer'
		AND system='$system'";
$result = mysqli_query($link, $query);
$sysid = mysqli_fetch_array($result)[0];
mysqli_free_result($result);

if (!$sysid)
{
	die('Error: No suitable system found! Please add the system and then try again');
}

$query = "SELECT id
	FROM sources
	WHERE name='$source'";
$result = mysqli_query($link, $query);
$sourceid = mysqli_fetch_array($result)[0];
mysqli_free_result($result);

if (!$sourceid)
{
	die('Error: No suitable source found! Please add the source and then try again');
}

// Then, parse the file and read in the information. Echo it out for safekeeping for now.
$handle = fopen($path_to_root."/temp/".$_GET["filename"], "r");
if ($handle)
{
	$old = false;
	$machinefound = false;
	$machinename = "";
	$description = "";
	$gameid = 0;
	$query = "";
	
	if ($_GET["debug"]=="1") echo "<h3>File Printout:</h3>";
	while (($line = fgets($handle)) !== false)
	{
		// If a machine or game tag is found, check to see if it's in the database
		// If it's not, add it to the database and then save the gameID
		
		// This first block is for XML-derived DATs
		if ((strpos($line, "<machine") !== false || strpos($line, "<game") !== false) && !$old)
		{
			$machinefound = true;
			$xml = simplexml_load_string($line.(strpos($line, "<machine")?"</machine>":"</game>"));
			$machinename = $xml->attributes()["name"];
			$gameid = add_game($sysid, $machinename, $sourceid, $link);
		}
		elseif (strpos($line, "<rom") !== false && $machinefound && !$old)
		{
			add_rom($line, $link, "rom");
		}
		elseif (strpos($line, "<disk") !== false && $machinefound && !$old)
		{
			add_rom($line, $link, "disk");
		}
		elseif ((strpos($line, "</machine>") !== false || strpos($line, "</game>") !== false) && !$old)
		{			
			echo "End of machine<br/><br/>";
			
			$machinefound = false;
			$machinename = "";
			$description = "";
			$gameid = 0;
		}
		
		// This block is for the old style DATs
		if (strpos($line, "game (") !== false)
		{
			$old = true;
		}
		elseif (strpos($line, "name") && $old)
		{
			$machinefound = true;
			$machinename = preg_replace("/^name \".*\"$/", "\1", trim($line));
			$gameid = add_game($sysid, $machinename, $sourceid, $link);
		}
		elseif (strpos($line, "rom (") !== false && $machinefound && $old)
		{
			add_rom_old($line, $link, "rom");
		}
		elseif (strpos($line, "disk (") !== false && $machinefound && $old)
		{
			add_rom_old($line, $link, "disk");
		}
		elseif (strpos($line, ")") !== false && $old)
		{
			echo "End of machine<br/><br/>";
				
			$machinefound = false;
			$machinename = "";
			$description = "";
			$gameid = 0;
		}
		
		// Print out all lines only in debug
		elseif ($_GET["debug"] == 1)
		{
			echo htmlspecialchars($line)."<br/><br/>";
		}
	}
	echo "<br/>";
	
	fclose($handle);
}
else
{
	die("Could not open file");
}

mysqli_close($link);

function add_game ($sysid, $machinename, $sourceid, $link)
{
	// WoD gets rid of anything past the first "(" as the name, we will do the same
	$machinename = preg_replace("/^(.*?) (\(|\[).*$/", "\1", $machinename);
	
	echo "Machine: ".$machinename."<br/>";
	
	$query = "SELECT id
	FROM games
	WHERE system='$sysid'
	AND name='$machinename'
	AND source=$sourceid";
	$result = mysqli_query($link, $query);
	if (mysqli_num_rows($result) == 0)
	{
		echo "No games found by that name. Creating new game.<br/>";
	
		$query = "INSERT INTO games (system, name, source)
		VALUES ($sysid, '$machinename', $sourceid)";
		$result = mysqli_query($link, $query);
		$gameid = mysqli_insert_id($link);
	}
	else
	{
		echo "Game found!<br/>";
	
		$gameid = mysqli_fetch_array($result)[0];
	}
	
	return $gameid;
}

function add_rom ($line, $link, $romtype)
{
	$xml = simplexml_load_string($line);
	add_rom_helper($link, $romtype, $xml->attributes()["name"], $xml->attributes()["size"],
			$xml->attributes()["crc"], $xml->attributes()["md5"], $xml->attributes()["sha1"]);
}
	
function add_rom_old($line, $link, $romtype)
{
	$rominfo = explode(" ", $line);
	$name = ""; $size = ""; $crc = ""; $md5 = ""; $sha1 = ""; 
	
	$next = "";
	foreach ($rominfo as $info)
	{
		if ($info == "name" || $info == "size" || $info == "crc" || $info == "md5" || $info == "sha1")
		{
			$next = $info;
		}
		elseif ($next != "")
		{
			switch ($next)
			{
				case "name": $name = trim($info, "\""); break;
				case "size": $size = $info; break;
				case "crc": $crc = $info; break;
				case "md5": $md5 = $info; break;
				case "sha1": $sha1 = $info; break;
				default: break;
			}
			$next = "";
		}
	}
	
	add_rom_helper($link, $romtype, $name, $size, $crc, $md5, $sha1);
}
	
function add_rom_helper($link, $romtype, $name, $size, $crc, $md5, $sha1)
{
	if ($romtype != "rom" && $romtype != "disk")
	{
		$romtype = "rom";
	}
	
	// Check for the existance of the rom in the given system and game
	// If it doesn't exist, create the rom with the information provided
	
	echo $tab.($romtype=="disk" ? "DiskName:" : "RomName: ").$name."<br/>".
			$tab."Size (bytes): ".$size."<br/>".
			$tab."CRC32: ".$crc."<br/>".
			$tab."MD5 Hash: ".$md5."<br/>".
			$tab."SHA1 Hash: ".$sha1."<br/><br/>";
	
	$query = "SELECT files.id
	FROM files
	JOIN checksums
	ON files.id=checksums.file
	WHERE files.name='".$name."'
		AND files.type='".$romtype."'
		AND checksums.size=".$size."
		AND checksums.crc='".$crc."'
		AND checksums.md5='".$md5."'
		AND checksums.sha1='".$sha1."'";
	$result = mysqli_query($link, $query);
	if (gettype($result)=="boolean" || mysqli_num_rows($result) == 0)
	{
		echo "ROM not found. Creating new ROM.<br/>";
		
		$query = "SELECT files.id FROM files WHERE files.name='".$name."'";
		$result = mysqli_query($link, $query);
		
		// See if there's any ROMs with the same name. If so, add a delimiter on the end of the name.
		if (gettype($result) != "boolean" && mysqli_num_rows($result) > 0)
		{
			$name = preg_replace("/^(.*)(\..*)/", "\1 (".
					($crc != "" ? $crc :
							($md5 != "" ? $md5 :
									($sha1 != "" ? $sha1 : "Alt"))).
					")\2", $name);
		}

		$query = "INSERT INTO files (setid, name, type)
		VALUES ($gameid,
		'".$name."',
		'$romtype')";
		$result = mysqli_query($link, $query);

		if (gettype($result)=="boolean" && $result)
		{
			echo "ROM created. Adding checksums<br/>";

			$query = "INSERT INTO checksums (file, size, crc, md5, sha1)
		VALUES (".mysqli_insert_id($link).",
				".$size.",
				'".$crc."',
				'".$md5."',
				'".$sha1."')";
			$result = mysqli_query($link, $query);

			if (gettype($result)=="boolean" && $result)
			{
				echo "Checksums added!";
			}
			else
			{
				echo "MYSQL Error! ".mysqli_error($link)."<br/>";
			}
		}
		else
		{
			echo "MYSQL Error! ".mysqli_error($link)."<br/>";
		}
	}
}
	
?>