<?php /* begin license *
 * 
 *     Tabbie, Debating Tabbing Software
 *     Copyright Contributors
 * 
 *     This file is part of Tabbie
 * 
 *     Tabbie is free software; you can redistribute it and/or modify
 *     it under the terms of the GNU General Public License as published by
 *     the Free Software Foundation; either version 2 of the License, or
 *     (at your option) any later version.
 * 
 *     Tabbie is distributed in the hope that it will be useful,
 *     but WITHOUT ANY WARRANTY; without even the implied warranty of
 *     MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *     GNU General Public License for more details.
 * 
 *     You should have received a copy of the GNU General Public License
 *     along with Tabbie; if not, write to the Free Software
 *     Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 * 
 * end license */

$ntu_controller = "import";
$moduletype="";

require("ntu_bridge.php");
require("view/header.php");
require("view/mainmenu.php");
require("includes/adjudicator.php");
?>
<h2>Import Backup</h2>
<p>
Use this page to import SQL files. SQL files can be created by clicking on "backup".<br/>
<b>Warning: this will erase all current data from your Tabbie installation!</b>

<form enctype="multipart/form-data" action="import.php" method="POST">
<input type="hidden" name="MAX_FILE_SIZE" value="1000000" />
Choose a file to upload: <input name="uploadedfile" type="file" /><br />
<input type="submit" value="Start Import" />
</form>


</p>
<?
if ( @$_FILES['uploadedfile']) {
    $problem = false;
    require_once("includes/dbconnection.php");
    
    if (!mysql_query("DROP DATABASE $database_name")) {
         $problem = true;
         print mysql_error() . "<br>";
    }
    if (!mysql_query("CREATE DATABASE $database_name")) {
         $problem = true;
         print mysql_error() . "<br>";
    }
    print mysql_error();
    mysql_select_db($database_name);
    
    $lines = split(";\n", file_get_contents($_FILES['uploadedfile']['tmp_name']));
    foreach ($lines as $line) {
		echo $line;
        if (! mysql_query($line)) {
            $problem = true;
            print "<p>" . mysql_error() . " in line '$line'</p>";
        }
	}

	//Upgrade files with 'conflicts' in adjudicator table]
	$query="SHOW COLUMNS FROM adjudicator";
	echo mysql_error();
	$result=mysql_query($query);
	while($row=mysql_fetch_assoc($result)){
		if($row['Field']=='conflicts'){
			$query=" CREATE TABLE `strikes` (`strike_id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY , `adjud_id` INT NOT NULL , `team_id` INT NULL , `univ_id` INT NULL , INDEX ( `adjud_id` )) ENGINE = MYISAM";
			mysql_query($query);
			echo mysql_error();
			$query="SELECT adjud_id, univ_id, conflicts FROM adjudicator";
			$strikeresult=mysql_query($query);
			while($adjudicator=mysql_fetch_assoc($strikeresult)){
			    $conflicts = preg_split("/, /", $adjudicator['conflicts'], -1, PREG_SPLIT_NO_EMPTY);
			    foreach ($conflicts as $conflict) {
			        $parts = split("[.]", $conflict);
			        if (sizeof($parts) == 1) {
			            add_strike_judge_univ($adjudicator['adjud_id'],__get_university_id_by_code($conflict));
			    	} elseif (sizeof($parts == 2)) {
			            add_strike_judge_team($adjudicator['adjud_id'],__get_team_id_by_codes($parts[0], $parts[1]));
					}
				}				
			}
			add_strike_judge_univ($adjudicator['adjud_id'],$adjudicator['univ_id']); //Strike from own institution.
			$query="ALTER TABLE adjudicator DROP COLUMN conflicts";
			mysql_query($query);
			echo mysql_error();
			print "<p>Conflicts converted to new format.</p>";
		}
    }

if (! $problem )
    print "<p><b>Imported File Succesfully</b></p>"; 

else 
    print "<p><b>Imported Encountered Problems</b></p>"; 
}

require('view/footer.php'); 
?>