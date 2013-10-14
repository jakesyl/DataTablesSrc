<?php

require_once('DT_Markdown.php');


class DT_Example
{
	static $tables = array();
	static $lookup_libraries = array();

	private $_file = null;

	private $_data = null;

	private $_xml = null;

	private $_template = null;

	private $_libs = null; // 2D array with js and css arrays

	private $_path_resolver = null;

	private $_additional_libs = null;


	function __construct ( $file=null, $template=null, $path_resolver=null, $libs=array() )
	{
		if ( $file !== null ) {
			$this->file( $file );
		}

		if ( $template !== null ) {
			$this->template( $template );
		}

		if ( $path_resolver !== null ) {
			$this->_path_resolver = $path_resolver;
		}

		$this->_libs = array(
			'css' => array(),
			'js'  => array()
		);

		$this->_additional_libs = $libs;

		$this->_data = json_decode( file_get_contents(
			dirname(__FILE__).'/../data.json'
		), true);
	}


	public function order ()
	{
		$attrs = $this->_xml->attributes();
		return isset( $attrs['order'] ) ?
			(int)$attrs['order'] :
			1;
	}

	public function title ()
	{
		return (string)$this->_xml->title;
	}


	public function file ( $file )
	{
		if ( ! is_file( $file ) ) {
			throw new Exception("File $file not found", 1);
		}

		$this->_file = $file;
		$this->_xml = simplexml_load_file( $file );
	}


	public function template ( $file )
	{
		if ( ! is_file( $file ) ) {
			throw new Exception("Template $file not found", 1);
		}

		$this->_template = $file;
	}


	public function transform ( $opts )
	{
		$xml = $this->_xml;

		// Resolve CSS libraries
		$this->_resolve_xml_libs( 'css', $xml->css );

		// Resolve JS libraries
		$this->_resolve_xml_libs( 'js', $xml->js );

		if ( isset( $this->_additional_libs['css'] ) ) {
			$this->_resolve_libs( 'css', $this->_additional_libs['css'] );
		}

		if ( isset( $this->_additional_libs['js'] ) ) {
			$this->_resolve_libs( 'js', $this->_additional_libs['js'] );
		}

		// Build data
		$tableHtml = $this->_build_table( (string)$xml['table-type'] );

		//echo $tableHtml;
		
		$template = file_get_contents( $this->_template );
		if ( ! $template ) {
			throw new Exception("Template file {$template} not found}", 1);
		}

		$template = str_replace( '{title}',     (string)$xml->title,          $template );
		$template = str_replace( '{info}',      DT_Markdown( $xml->info ),    $template );
		$template = str_replace( '{css-libs}',  $this->_format_libs( 'css' ), $template );
		$template = str_replace( '{js-libs}',   $this->_format_libs( 'js' ),  $template );
		$template = str_replace( '{table}',     $tableHtml,                   $template );

		if ( isset( $xml->{'demo-html'} ) ) {
			$template = str_replace( '{demo-html}', $this->innerXML($xml->{'demo-html'}), $template );
		}
		else {
			$template = str_replace( '{demo-html}', '', $template );
		}

		if ( isset( $opts['toc'] ) ) {
			$template = str_replace( '{toc}', $opts['toc'], $template );
		}

		$template = $this->_htmlTidy( $template );

		// After the tidy to preserve white space as tidy "cleans" it up
		$template = str_replace( '{css}',      $this->_plain( 'css' ),       $template );
		$template = str_replace( '{js}',       $this->_plain( 'js' ),        $template );

		$template = preg_replace( '/\t<style type="text\/css">\n\n\t<\/style>/m', "", $template );

		return $template;
	}

	private function innerXML( $node )
	{
		$content = '';
		foreach( $node->children() as $child ) {
			$content .= $child->asXml();
		}
		return $content;
	}


	private function _htmlTidy( $html )
	{
		$tidy = new tidy();
		$tidy->parseString( $html, array(
			'indent' => 2,
			'indent-spaces' => 4,
			'new-blocklevel-tags' => 'section',
			'new-pre-tags' => 'script',
			'output-html' => 1,
			'wrap' => 120
		) );
		$tidy->cleanRepair();

		// Tody up of the tidied HTML!
		$str = preg_replace( '/\n<\/script>/m', '</script>', $tidy );
		$str = preg_replace( '/<\/td>\n\n/m', "</td>\n", $str );
		$str = preg_replace( '/<\/th>\n\n/m', "</th>\n", $str );
		$str = preg_replace( '/<\/tr>\n\n/m', "</tr>\n", $str );
		$str = preg_replace( '/<\/li>\n\n/m', "</li>\n", $str );
		$str = preg_replace( '/<\/h3>\n\n/m', "</h3>\n", $str );
		$str = preg_replace( '/\n\n<html>/m', "\n<html>", $str );
		$str = preg_replace( '/    /m', "\t", $str );
		//$str = preg_replace( '/^\n+|^[\t\s]*\n+/m', '', $tidy );
		return $str;
	}

	private function _column( $name, $type, $row=null )
	{
		if ( is_callable( $name ) ) {
			return $name( $type, $row );
		}

		switch( $name ) {
			case '':
				if      ( $type === 'title' ) { return ''; }
				else if ( $type === 'data' )  { return ''; }
				break;

			case 'name':
				if      ( $type === 'title' ) { return 'Name'; }
				else if ( $type === 'data' )  { return $row['first_name'].' '.$row['last_name']; }
				break;

			case 'first_name':
				if      ( $type === 'title' ) { return 'First name'; }
				else if ( $type === 'data' )  { return $row['first_name']; }
				break;

			case 'last_name':
				if      ( $type === 'title' ) { return 'Last name'; }
				else if ( $type === 'data' )  { return $row['last_name']; }
				break;

			case 'age':
				if      ( $type === 'title' ) { return 'Age'; }
				else if ( $type === 'data' )  { return $row['age']; }
				break;

			case 'position':
				if      ( $type === 'title' ) { return 'Position'; }
				else if ( $type === 'data' )  { return $row['position']; }
				break;

			case 'salary':
				if      ( $type === 'title' ) { return 'Salary'; }
				else if ( $type === 'data' )  { return '$'.number_format($row['salary']); }
				break;

			case 'start_date':
				if      ( $type === 'title' ) { return 'Start date'; }
				else if ( $type === 'data' )  { return $row['start_date']; }
				break;

			case 'extn':
				if      ( $type === 'title' ) { return 'Extn.'; }
				else if ( $type === 'data' )  { return $row['extn']; }
				break;

			case 'email':
				if      ( $type === 'title' ) { return 'E-mail'; }
				else if ( $type === 'data' )  { return $row['email']; }
				break;

			case 'office':
				if      ( $type === 'title' ) { return 'Office'; }
				else if ( $type === 'data' )  { return $row['office']; }
				break;

			default:
				throw new Exception("Unknown column: ".$name, 1);
				break;
		}
	}


	private function _build_table ( $type )
	{
		if ( $type === '' || $type === null ) {
			return '';
		}

		if ( strpos($type, '|') ) {
			$a = explode('|', $type);
			$t = '';

			for ( $i=0, $ien=count($a) ; $i<$ien ; $i++ ) {
				$t .= $this->_build_table( $a[$i] );
			}

			return $t;
		}

		$id = 'example';
		if ( isset( $this->_xml['table-id'] ) ) {
			$id = (string)$this->_xml['table-id'];
		}

		$class = 'display';
		if ( isset( $this->_xml['table-class'] ) ) {
			$class = (string)$this->_xml['table-class'];
		}

		if ( ! isset( DT_Example::$tables[ $type ] ) ) {
			throw new Exception("Unknown table type: ".$type, 1);
		}
		$construction = DT_Example::$tables[ $type ];
		$columns = $construction['columns'];

		$t = '<table id="'.$id.'" class="'.$class.'" cellspacing="0" width="100%">';

		// Build the header
		if ( $construction['header'] ) {
			if ( is_callable( $construction['header'] ) ) {
				$t .= $construction['header']();
			}
			else {
				$cells = '';
				for ( $i=0, $ien=count($columns) ; $i<$ien ; $i++ ) {
					$cells .= '<th>'.$this->_column( $columns[$i], 'title' ).'</th>';
				}
				$t .= '<thead>';
				$t .= '<tr>'.$cells.'</tr>';
				$t .= '</thead>';
			}
		}
		
		// Footer
		if ( $construction['footer'] ) {
			if ( is_callable( $construction['footer'] ) ) {
				$t .= $construction['footer']();
			}
			else {
				$cells = '';
				for ( $i=0, $ien=count($columns) ; $i<$ien ; $i++ ) {
					$cells .= '<th>'.$this->_column( $columns[$i], 'title' ).'</th>';
				}
				$t .= '<tfoot>';
				$t .= '<tr>'.$cells.'</tr>';
				$t .= '</tfoot>';
			}
		}
		
		// Body
		if ( $construction['body'] ) {
			if ( is_callable( $construction['body'] ) ) {
				$t .= $construction['body']();
			}
			else {
				$t .= '<tbody>';
				for ( $j=0, $jen=count($this->_data) ; $j<$jen ; $j++ ) {
					if ( isset( $construction['filter'] ) && $construction['filter']($this->_data[$j]) === false ) {
						continue;
					}

					$cells = '';
					for ( $i=0, $ien=count($columns) ; $i<$ien ; $i++ ) {
						$cells .= '<td>'.$this->_column( $columns[$i], 'data', $this->_data[$j] ).'</td>';
					}
					$t .= '<tr>'.$cells.'</tr>';
				}
				$t .= '</tbody>';
			}
		}
		

		$t .= '</table>';

		return $t;
	}


	private function _plain ( $type )
	{
		$out = array();
		$tags = $type === 'js' ?
			$this->_xml->js :
			$this->_xml->css;

		foreach( $tags as $src ) {
			if ( (string)$src !== '' ) {
				if ( $type === 'css' ) {
					$out[] = (string)$src;
				}
				else {
					$out[] = (string)$src;
				}
			}
		}

		return implode( '', $out );
	}



	private function _format_libs ( $type )
	{
		$out = array();
		$libs = $this->_libs[ $type ];

		for ( $i=0, $ien=count($libs) ; $i<$ien ; $i++ ) {
			$file = $libs[$i]; // needs a path

			if ( strpos($file, '//') !== 0 ) {
				$file = call_user_func( $this->_path_resolver, $file );
			}

			if ( $type === 'js' ) {
				$out[] = '<script type="text/javascript" language="javascript" src="'.$file.'"></script>';
			}
			else {
				$out[] = '<link rel="stylesheet" type="text/css" href="'.$file.'">';
			}
		}

		return implode( '', $out );
	}


	private function _resolve_xml_libs ( $type, $libs )
	{
		$a = array();

		foreach( $libs as $lib ) {
			if ( isset( $lib['lib'] ) ) {
				$split_attr = explode( ' ', (string)$lib['lib'] );

				for ( $i=0, $ien=count($split_attr) ; $i<$ien ; $i++ ) {
					$a[] = $split_attr[$i];
				}
			}
		}

		$this->_resolve_libs( $type, $a );
	}


	private function _resolve_libs ( $type, $libs )
	{
		$host = &$this->_libs[ $type ];
		$srcLibs = DT_Example::$lookup_libraries[ $type ];

		for ( $i=0, $ien=count($libs) ; $i<$ien ; $i++ ) {
			$srcLib = $libs[$i];

			if ( isset( $srcLibs[ $srcLib ] ) ) {
				if ( ! in_array( $srcLibs[ $srcLib ], $host ) ) {
					$host[] = $srcLibs[ $srcLib ];
				}
			}
			else {
				throw new Exception("Unknown {$type} library: ".$srcLib, 1);
			}
		}
	}
}


DT_Example::$lookup_libraries['css'] = array(
	'jqueryui'              => '//code.jquery.com/ui/1.10.3/themes/smoothness/jquery-ui.css',
	'bootstrap'             => '//netdna.bootstrapcdn.com/bootstrap/3.0.0/css/bootstrap.min.css',
	'foundation'            => '//cdnjs.cloudflare.com/ajax/libs/foundation/4.3.1/css/foundation.min.css'
);


DT_Example::$lookup_libraries['js'] = array(
	'jqueryui'              => '//code.jquery.com/ui/1.10.3/jquery-ui.js'
);


DT_Example::$tables['html'] = array(
	'columns' => array( 'name', 'position', 'office', 'age', 'start_date', 'salary' ),
	'header'  => true,
	'footer'  => true,
	'body'    => true
);

DT_Example::$tables['ajax'] = array(
	'columns' => array( 'name', 'position', 'office', 'extn', 'start_date', 'salary' ),
	'header'  => true,
	'footer'  => true,
	'body'    => false
);

DT_Example::$tables['html-comma'] = array(
	'columns' => array( 'name', 'position', 'office', 'age', 'start_date', function ( $type, $row ) {
		return $type === 'title' ? 'Salary' : '$'.number_format($row['salary'], 0, ',', '.').',00';
	} ),
	'header'  => true,
	'footer'  => true,
	'body'    => true
);


DT_Example::$tables['html-wide'] = array(
	'columns' => array( 'first_name', 'last_name', 'position', 'office', 'age', 'start_date', 'salary', 'extn', 'email' ),
	'header'  => true,
	'footer'  => false,
	'body'    => true
);


DT_Example::$tables['html-split-name'] = array(
	'columns' => array( 'first_name', 'last_name', 'position', 'office', 'salary' ),
	'header'  => true,
	'footer'  => false,
	'body'    => true
);


DT_Example::$tables['html-total-footer'] = array(
	'columns' => array( 'first_name', 'last_name', 'position', 'office', 'salary' ),
	'header'  => true,
	'footer'  => function () {
		return '<tfoot>'.
				'<tr>'.
					'<th colspan="4" style="text-align:right">Total:</th>'.
					'<th></th>'.
				'</tr>'.
			'</tfoot>';
	},
	'body'    => true
);


DT_Example::$tables['ajax-details'] = array(
	'columns' => array( '', 'name', 'position', 'office', 'age', 'salary' ),
	'header'  => true,
	'footer'  => true,
	'body'    => false
);


DT_Example::$tables['html-index'] = array(
	'columns' => array( '', 'name', 'position', 'office', 'age', 'salary' ),
	'header'  => true,
	'footer'  => true,
	'body'    => true
);


DT_Example::$tables['html-office-edin'] = array(
	'columns' => array( 'name', 'position', 'office', 'age', 'salary' ),
	'header'  => true,
	'footer'  => true,
	'body'    => true,
	'filter'  => function ( $data ) {
		return $data['office'] === 'Edinburgh';
	}
);


DT_Example::$tables['html-office-london'] = array(
	'columns' => array( 'name', 'position', 'office', 'age', 'salary' ),
	'header'  => true,
	'footer'  => true,
	'body'    => true,
	'filter'  => function ( $data ) {
		return $data['office'] === 'London';
	}
);


DT_Example::$tables['html-add-api'] = array(
	'columns' => array(
		function () { return 'Column 1'; },
		function () { return 'Column 2'; },
		function () { return 'Column 3'; },
		function () { return 'Column 4'; },
		function () { return 'Column 5'; }
	),
	'header'  => true,
	'footer'  => true,
	'body'    => true,
	'filter'  => function ( $data ) {
		return false;
	}
);


DT_Example::$tables['html-form'] = array(
	'columns' => array(
		'name',
		function ($type, $row) { return $type == 'title' ?
			'Age' :
			'<input type="text" id="row-'.$row['id'].'-age" name="row-'.$row['id'].'-age" value="'.$row['age'].'">';
		},
		function ($type, $row) { return $type == 'title' ?
			'Position' :
			'<input type="text" id="row-'.$row['id'].'-position" name="row-'.$row['id'].'-position" value="'.$row['position'].'">';
		},
		function ($type, $row) {
			$c = 'selected="selected"';
			return $type == 'title' ?
				'Office' :
				'<select size="1" id="row-'.$row['id'].'-office" name="row-'.$row['id'].'-office">'.
					'<option value="Edinburgh" '    .($row['office'] === 'Edinburgh' ? $c : '').    '>Edinburgh</option>'.
					'<option value="London" '       .($row['office'] === 'London' ? $c : '').       '>London</option>'.
					'<option value="New York" '     .($row['office'] === 'New York' ? $c : '').     '>New York</option>'.
					'<option value="San Francisco" '.($row['office'] === 'San Francisco' ? $c : '').'>San Francisco</option>'.
					'<option value="Tokyo" '        .($row['office'] === 'Tokyo' ? $c : '').        '>Tokyo</option>'.
				'</select>';
		}
	),
	'header'  => true,
	'footer'  => true,
	'body'    => true
);


DT_Example::$tables['html-complex-header'] = array(
	'columns' => array( 'name', 'position', 'salary', 'office', 'extn', 'email' ),
	'header'  => function () {
		return '<thead>'.
				'<tr>'.
					'<th rowspan="2">Name</th>'.
					'<th colspan="2">HR Information</th>'.
					'<th colspan="3">Contact</th>'.
				'</tr>'.
				'<tr>'.
					'<th>Position</th>'.
					'<th>Salary</th>'.
					'<th>Office</th>'.
					'<th>Extn.</th>'.
					'<th>E-mail</th>'.
				'</tr>'.
			'</thead>';
	},
	'footer'  => true,
	'body'    => true
);