<?php

/**
 * GoogleMapField
 * Lets you record a precise location using latitude/longitude fields to a
 * DataObject. Displays a map using the Google Maps API. The user may then
 * choose where to place the marker; the landing coordinates are then saved.
 * You can also search for locations using the search box, which uses the Google
 * Maps Geocoding API.
 * @author <@willmorgan>
 */
class GoogleMapField extends FormField {

	protected $data;

	/**
	 * @var FormField
	 */
	protected $latField;

	/**
	 * @var FormField
	 */
	protected $lngField;

	/**
	 * @var FormField
	 */
	protected $zoomField;

	/**
	 * The merged version of the default and user specified options
	 * @var array
	 */
	protected $options = array();

	/**
	 * @var boolean
	 */
	static protected $js_inserted = false;

	/**
	 * @param DataObject $data The controlling dataobject
	 * @param string $title The title of the field
	 * @param array $options Various settings for the field
	 */
	public function __construct(DataObject $data, $title, $options = array()) {
		$this->data = $data;

		// Set up fieldnames
		$this->setupOptions($options);

		$fieldNames = $this->getOption('field_names');

		// Auto generate a name
		$name = sprintf('%s_%s_%s', $data->class, $fieldNames['Latitude'], $fieldNames['Longitude']);

		// Create the latitude/longitude hidden fields
		$this->latField = HiddenField::create(
			$name.'[Latitude]',
			'Lat',
			$this->recordFieldData('Latitude')
		)->addExtraClass('googlemapfield-latfield');

		$this->lngField = HiddenField::create(
			$name.'[Longitude]',
			'Lng',
			$this->recordFieldData('Longitude')
		)->addExtraClass('googlemapfield-lngfield');

		$this->zoomField = HiddenField::create(
			$name.'[Zoom]',
			'Zoom',
			$this->recordFieldData('Zoom')
		)->addExtraClass('googlemapfield-zoomfield');

		$this->children = new FieldList(
			$this->latField,
			$this->lngField,
			$this->zoomField
		);

		if($this->options['show_search_box']) {
			$this->children->push(
				TextField::create('Search')
				->addExtraClass('googlemapfield-searchfield')
				->setAttribute('placeholder', 'Search for a location')
			);
		}

		parent::__construct($name, $title);
	}

	/**
	 * Merge options preserving the first level of array keys
	 * @param array $options
	 */
	public function setupOptions(array $options) {
		$this->options = static::config()->default_options;
		foreach($this->options as $name => &$value) {
			if(isset($options[$name])) {
				if(is_array($value)) {
					$value = array_merge($value, $options[$name]);
				}
				else {
					$value = $options[$name];
				}
			}
		}
	}

	/**
	 * @param array $properties
	 * @see https://developers.google.com/maps/documentation/javascript/reference
	 * {@inheritdoc}
	 */
	public function Field($properties = array()) {
		$key = $this->options['api_key'] ? "&key=".$this->options['api_key'] : "";
		Requirements::javascript(GOOGLEMAPFIELD_BASE .'/javascript/GoogleMapField.js');
		Requirements::javascript("//maps.googleapis.com/maps/api/js?callback=googlemapfieldInit".$key);
		Requirements::css(GOOGLEMAPFIELD_BASE .'/css/GoogleMapField.css');
		$jsOptions = array(
			'coords' => array(
				$this->recordFieldData('Latitude'),
				$this->recordFieldData('Longitude')
			),
			'map' => array(
				'zoom' => $this->recordFieldData('Zoom') ?: $this->getOption('map.zoom'),
				'mapTypeId' => 'ROADMAP',
			),
		);

		$jsOptions = array_replace_recursive($this->options, $jsOptions);
		$this->setAttribute('data-settings', Convert::array2json($jsOptions));

		return parent::Field($properties);
	}

	/**
	 * {@inheritdoc}
	 */
	public function setValue($record) {
		$this->latField->setValue(
			$record['Latitude']
		);
		$this->lngField->setValue(
			$record['Longitude']
		);
		$this->zoomField->setValue(
			$record['Zoom']
		);
		return $this;
	}

	/**
	 * Take the latitude/longitude fields and save them to the DataObject.
	 * {@inheritdoc}
	 */
	public function saveInto(DataObjectInterface $record) {
		$record->setCastedField($this->childFieldName('Latitude'), $this->latField->dataValue());
		$record->setCastedField($this->childFieldName('Longitude'), $this->lngField->dataValue());
		$record->setCastedField($this->childFieldName('Zoom'), $this->zoomField->dataValue());
		return $this;
	}

	/**
	 * @return FieldList The Latitude/Longitude fields
	 */
	public function getChildFields() {
		return $this->children;
	}

	protected function childFieldName($name) {
		$fieldNames = $this->getOption('field_names');
		return $fieldNames[$name];
	}

	protected function recordFieldData($name) {
		$fieldName = $this->childFieldName($name);
		return $this->data->$fieldName ?: $this->getDefaultValue($name);
	}

	public function getDefaultValue($name) {
		$fieldValues = $this->getOption('default_field_values');
		return isset($fieldValues[$name]) ? $fieldValues[$name] : null;
	}

	/**
	 * @return string The VALUE of the Latitude field
	 */
	public function getLatData() {
		$fieldNames = $this->getOption('field_names');
		return $this->data->$fieldNames['Latitude'];
	}

	/**
	 * @return string The VALUE of the Longitude field
	 */
	public function getLngData() {
		$fieldNames = $this->getOption('field_names');
		return $this->data->$fieldNames['Longitude'];
	}

	/**
	 * Get the merged option that was set on __construct
	 * @param string $name The name of the option
	 * @return mixed
	 */
	public function getOption($name) {
		// Quicker execution path for "."-free names
		if (strpos($name, '.') === false) {
			if (isset($this->options[$name])) return $this->options[$name];
		} else {
			$names = explode('.', $name);

			$var = $this->options;

			foreach($names as $n) {
				if(!isset($var[$n])) {
					return null;
				}
				$var = $var[$n];
			}

			return $var;
		}
	}

	/**
	 * Set an option for this field
	 * @param string $name The name of the option to set
	 * @param mixed $val The value of said option
	 * @return $this
	 */
	public function setOption($name, $val) {
		// Quicker execution path for "."-free names
		if(strpos($name,'.') === false) {
			$this->options[$name] = $val;
		} else {
			$names = explode('.', $name);

			// We still want to do this even if we have strict path checking for legacy code
			$var = &$this->options;

			foreach($names as $n) {
				$var = &$var[$n];
			}

			$var = $val;
		}
		return $this;
	}

}
