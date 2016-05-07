<?php
/**
 *
 * @license    GPL 3 (http://www.gnu.org/licenses/gpl.html)
 * @author     Julian Kunkel <Julian.Kunkel@googlemail.com>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();


class syntax_plugin_datagraph extends DokuWiki_Syntax_Plugin {
    protected $db = null;

    function syntax_plugin_datagraph() {
       $sqlite = plugin_load('helper', 'sqlite');
	if(!$sqlite){
	    msg('This plugin requires the sqlite plugin. Please install it', -1);
	    return;
	}
	// initialize the database connection
	if(! $sqlite->init('data', dirname(__FILE__) . '/db/')){
            msg('Could not load the database');
	    return;
	}
	$this->db = $sqlite;
    }

    function getType() {
        return 'substition';
    }

    function getSort() {
        return 160;
    }

    function getPType() {
        return 'block';
    }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('----+ *datagraph *-+\n.*?\n---+', $mode, 'plugin_datagraph');
    }

    function handle($match, $state, $pos, Doku_Handler $handler) {
	$kv = array("group by" => "", "aggregate" => "", "digits" => "1", "choosableGroups" => "", "choosableAggregates" => "", "filterClass" => NULL, "choosableYear" => "");

	$match = explode("\n", $match);
	foreach($match as $line){
		$d = explode(":", $line, 2);
		if(count($d) == 2){
			$kv[trim($d[0])] = trim($d[1]);
		}
	}
        return $kv;
    }

    function render($format, Doku_Renderer $R, $kv) {
        if($format != 'xhtml') return false;
	ob_start();
	$format = intval($kv["digits"]);
	$groups = explode(",",$kv["choosableGroups"]);
	$aggregates = explode(",",$kv["choosableAggregates"]);
  $years = explode(",",$kv["choosableYear"]);
  $filter = $kv["filterClass"];
  $filter_short = "";
  $filter2value = $years[0]; // query only the first year
  $filter2key = "year";

  if ($_GET["year"] != NULL){
    $filter2value = $_GET["year"];
  }

  if($filter != NULL){
    # TODO prevent SQL injections
    $filter_short= 'p.class = "' .$filter . '"';
  }

	// defaults:
	$groupBy = $kv["group by"] ;
	$agg = $kv["aggregate"];

	if (count($groups) > 0){
		// allow to select the grouping.
		$v = $_GET["group"];
		if ($v != NULL){
			// check if the selection is allowed.
			foreach($groups as $g){
				if(trim($g) == $v){
					$groupBy = $v;
					break;
				}
			}
		}
	}
	if (count($aggregates) > 0){
		// allow to select the grouping.
		$v = $_GET["aggregate"];
		if ($v != NULL){
			// check if the selection is allowed.
			foreach($aggregates as $g){
				if(trim($g) == $v){
					$agg = $v;
					break;
				}
			}
		}
	}

	if (count($groups) > 0){
		// create link map:
		print("\nGroup by: ");
		$first = True;
		foreach($groups as $g){
			$g = trim($g);
			if (! $first){
				print(", ");
			}
			if($groupBy == $g){
				$mark = "**";
			}else{
				$mark = "";
			}
			print($mark . "[[?group=". $g . "&aggregate=". $agg ."|". $g . "]]". $mark);
			$first = False;
		}
		print("\n");
	}
	if (count($groups) > 0){
		// create link map:
		print("\nAggregate: ");
		$first = True;
		foreach($aggregates as $g){
			$g = trim($g);
			if (! $first){
				print(", ");
			}
			if($agg == $g){
				$mark = "**";
			}else{
				$mark = "";
			}
			print($mark."[[?group=". $groupBy . "&aggregate=". $g ."|". $g . "]]".$mark);
			$first = False;
		}
		print("\n");
	}

	$detailedGrouping = $_GET["groupDetails"];
	if ($detailedGrouping == NULL){
		$detailedGrouping = FALSE;
	}

	$arr = $this->db->res2arr($this->db->query('select d2.value as k, sum(d.value) as s, count(d.value) as m, d.value as metrics, group_concat(page) as pages from pages as p join data as d on d.pid = p.pid  join data as d2 on d2.pid = p.pid   join data as d3 on d3.pid = p.pid  ' . $filter_joins . ' where ' . $filter_short . ' and d.key="' . $agg . '" and d2.key="' . $groupBy. '" and d3.key = "' . $filter2key . '" and d3.value = "' . $filter2value . '" group by d2.value'));

	$metrics = explode(" ", $arr[0]["metrics"]);
	if( count($metrics == 2)){
		$metrics = " " . $metrics[1];
	}else{
		$metrics = "";
	}


	$count = count($arr);
	for( $i=0; $i < $count; $i++){
		if(strlen($arr[$i]["k"]) < 2){
			$arr[$i]["k"] = "Unknown";
		}
		// remove rows with a subprefix
		if( ! $detailedGrouping){
			$curname = explode(" ", trim($arr[$i]["k"]));
			if(count($curname) > 1 || ctype_lower($curname[0][0])){
				unset($arr[$i]);
			}
		}
	}

	print("<c3>{  data: {    columns: [");
	foreach( $arr as $line ){
        	print("['" . $line["k"] .  "', ".  $line["s"]  ."], ");
	}
	print("],    type : 'pie',  }}</c3>\n");

	// header
	print("=== " . ucfirst($agg) . " by "  .  $groupBy . "  ====\n");

	print("^ " .  ucfirst($groupBy)  . "^  (SUM)  ^   mean  ^  # systems  ^  System list  ^\n");
	// body
	$sum = 0;
  $systemCount = 0;
	foreach( $arr as $line ){
		$sum = $sum + $line["s"];
    $systemCount = $systemCount + $line["m"];
        	print("|" . $line["k"] .  "|   ".  number_format($line["s"], $format) . $metrics   ."  |  " .  number_format($line["s"]/$line["m"], $format)   . $metrics   . "  |  " .   $line["m"] . "|  ");
		$systems = "";
		foreach (explode(",", $line["pages"]) as $page){
			$systems .= "[[". $page .  "]], "; // . "|" . explode("/", $page, 2)[1]
		}
		print( trim($systems, " ,") . "|\n");
	}
	print("| (SUM) **all systems** |  " . $sum  . $metrics . " |    " .  number_format(($sum / $systemCount), 1) . $metrics . "|  " . $systemCount .   "| \n");


	//print("<c3>{  data: {    columns: [      ['data1', 30],      ['data2', 120],    ],    type : 'pie',  }}</c3>");
	$R->doc .= p_render( "xhtml", p_get_instructions( ob_get_contents() ), $info);
	ob_end_clean();

        return true;
    }
}
