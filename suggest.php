<?php
require_once './includes/backend_initial.php';

$suggest = $_REQUEST['suggest'];
$subject = $_REQUEST['subject'];

$wrongLayoutEN_RU = Array(
	'a' => 'ф',
	'b' => 'и',
	'c' => 'с',
	'd' => 'в',
	'e' => 'у',
	'f' => 'а',
	'g' => 'п',
	'h' => 'р',
	'i' => 'ш',
	'j' => 'о',
	'k' => 'л',
	'l' => 'д',
	'm' => 'ь',
	'n' => 'т',
	'o' => 'щ',
	'p' => 'з',
	'q' => 'й',
	'r' => 'к',
	's' => 'ы',
	't' => 'е',
	'u' => 'г',
	'v' => 'м',
	'w' => 'ц',
	'x' => 'ч',
	'y' => 'н',
	'z' => 'я',
	',' => 'б',
	'.' => 'ю',
	'[' => 'х',
	']' => 'ъ',
	';' => 'ж',
	"'" => 'э',
	'`' => 'ё'
);

$wrongLayoutRU_EN = array_flip($wrongLayoutEN_RU);

$translitRU = Array(
	'a' => 'a',
	'b' => 'б',
	'c' => Array('ц', 'с'),
	'd' => 'д',
	'e' => 'е',
	'f' => 'ф',
	'g' => Array('г', 'ж', 'дж'),
	'h' => Array('x', 'г'),
	'i' => 'и',
	'j' => Array('ж', 'дж'),
	'k' => 'к',
	'l' => 'л',
	'm' => 'м',
	'n' => 'н',
	'o' => 'о',
	'p' => 'п',
	'q' => Array('ку', 'кв', 'к'),
	'r' => 'р',
	's' => 'с',
	't' => 'т',
	'u' => 'у',
	'v' => 'в',
	'w' => Array('в','у'),
	'x' => 'кс',
	'y' => Array('и','ы', 'й'),
	'z' => ''
);

function fix_wrong_case (){

}

switch ($suggest):
	
	case 'tags':

		$subjects = explode(' ', trim($subject));

		foreach ($subjects as $n => $tag){
			$subjects[$n] = strtolower($tag);
			//$subjects[] = fix_wrong_case($tag);
		}

		$result = $db->select(
			'SELECT
				  id
				, name
				, type
				, strict
			FROM ?_tags
			WHERE name REGEXP ?
			LIMIT 10
			', implode('|', $subjects)
		);
		
	break;
	
	default:

		$result = 'command not found';

endswitch;

//sleep(2);

$GLOBALS['_RESULT'] = $result;
?>
