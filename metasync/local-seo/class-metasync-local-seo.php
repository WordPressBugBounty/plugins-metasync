<?php

/**
 * The local business SEO functionality of the plugin.
 *
 *
 * @link       http://linkgraph.io
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/local-seo
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Local_SEO
{

	private $escapers;
	private $replacements;

	public function __construct()
	{
		$this->escapers = array("\\", "/", "\"");
		$this->replacements = array("", "", "");
	}

	/**
	 * Add Type Person or Organization schema.
	 *
	 */
	public function local_business_ld_json()
	{

		$local_seo_options = '';
		$type = '';
		$about_page = '';
		$contact_page = '';
		$email = '';
		$data = null;

		if (get_option(Metasync::option_name)) {
			$local_seo_options = get_option(Metasync::option_name)['localseo'] ?? '';
			$type = $local_seo_options['local_seo_person_organization'] ?? '';
			$about_page = $local_seo_options['local_seo_about_page'] ?? '';
			$contact_page = $local_seo_options['local_seo_contact_page'] ?? '';
			$email = $local_seo_options['local_seo_email'] ?? '';
		}

		$entity = [
			'@type' => '',
			'name'  => '',
			'email' => $email,
			'url'   => get_home_url(),
		];

		switch ($type) {
			case 'Organization':
				$data = $this->organization($entity, $local_seo_options);
				break;
			case 'Person':
				$data = $this->person($entity, $local_seo_options);
				break;
		}

		if (($about_page == get_the_ID()) || ($contact_page == get_the_ID())) {
			return $data;
		}
	}

	/**
	 * Structured data for Organization.
	 *
	 * @param array $entity Array of JSON-LD entity.
	 * @param array $data  Array of JSON-LD data.
	 */
	private function organization($entity, $local_seo_options)
	{
		$name            = str_replace($this->escapers, $this->replacements, $local_seo_options['local_seo_name']);
		$type            = $local_seo_options['local_seo_business_type'];
		$entity['@type'] = $type ? $type : 'Organization';
		$entity['name']  = $name ? $name : str_replace($this->escapers, $this->replacements, get_bloginfo('name'));

		//Logo.
		if ($logo = $local_seo_options['local_seo_logo']) {
			$entity['logo']  = wp_get_attachment_image_url($logo);
		}

		if ('Organization' !== $type) {
			$entity['@type'] = \array_values(array_filter([$type, 'Organization']));
		}

		//Price Range.
		if ($price_range = $local_seo_options['local_seo_price_range']) {
			$entity['priceRange'] = $price_range;
		}

		$this->add_geo_cordinates($entity, $local_seo_options);
		$this->add_address($entity, $local_seo_options);
		$this->add_contact_points($entity, $local_seo_options);
		$this->add_business_hours($entity, $local_seo_options);

		return $entity;
	}

	/**
	 * Structured data for Person.
	 *
	 * @param array  $entity  Array of JSON-LD entity.
	 * @param JsonLD $json_ld JsonLD instance.
	 */
	private function person($entity, $local_seo_options)
	{
		$name = $local_seo_options['local_seo_name'];
		$phone = $local_seo_options['local_seo_phone'];

		if (!$name) {
			return false;
		}

		unset($entity['@id']);

		$entity['@type'] = 'Person';
		$entity['name']  = str_replace($this->escapers, $this->replacements, $name);
		$entity['phone']  = $phone;

		//Logo.
		if ($logo = $local_seo_options['local_seo_logo']) {
			$entity['logo']  = wp_get_attachment_image_url($logo);
		}

		$this->add_address($entity, $local_seo_options);

		return $entity;
	}

	/**
	 * Add Contact points in the Organization schema.
	 *
	 * @param array $entity Array of JSON-LD entity.
	 */
	private function add_contact_points(&$entity, $local_seo_options)
	{
		$numbers = $local_seo_options['phonenumber'] ?? '';
		$types = $local_seo_options['phonetype'] ?? '';

		$phone_numbers = [];
		if ($types && $numbers) {
			$phone_numbers = array_combine($types, $numbers);
		}

		if (empty($phone_numbers)) {
			return;
		}

		$contacts = [];

		foreach ($phone_numbers as $type => $number) {
			if (empty($number && $type)) {
				continue;
			}

			$contacts[] = [
				'@type'       => 'ContactPoint',
				'telephone'   => $number,
				'contactType' => $type,
			];
		}

		if (!empty($contacts)) {
			$entity['contactPoint'] = $contacts;
		}
	}

	/**
	 * Add geo coordinates in Place entity.
	 *
	 * @param array $entity Array of JSON-LD entity.
	 */
	private function add_geo_cordinates(&$entity, $local_seo_options)
	{
		$geo = explode(',', $local_seo_options['local_seo_geo_coordinates']);
		if (!isset($geo[0], $geo[1])) {
			return;
		}

		$entity['geo'] = [
			'@type'     => 'GeoCoordinates',
			'latitude'  => $geo[0],
			'longitude' => $geo[1],
		];

		$entity['hasMap'] = 'https://www.google.com/maps/search/?api=1&query=' . join(',', $geo);
	}

	/**
	 * Add address in Place entity.
	 *
	 * @param array $entity Array of JSON-LD entity.
	 */
	private function add_address(&$entity, $local_seo_options)
	{
		$address = $local_seo_options['address'];
		$street = $address['street'];
		$locality = $address['locality'];
		$region = $address['region'];
		$postal_code = $address['postalcode'];
		$country = $address['country'];

		$entity['address'] = [
			"@type" => "PostalAddress",
			"streetAddress" => str_replace($this->escapers, $this->replacements, $street),
			"addressLocality" => str_replace($this->escapers, $this->replacements, $locality),
			"addressRegion" => str_replace($this->escapers, $this->replacements, $region),
			"postalCode" => str_replace($this->escapers, $this->replacements, $postal_code),
			"addressCountry" => str_replace($this->escapers, $this->replacements, $country)
		];
	}

	/**
	 * Add business hours in the Organization schema.
	 *
	 * @param array $entity Array of JSON-LD entity.
	 */
	private function add_business_hours(&$entity, $local_seo_options)
	{
		$times = $local_seo_options['times'] ?? '';
		$days = $local_seo_options['days'] ?? '';

		$opening_hours = [];
		if ($days && $times) {
			$opening_hours = array_combine($days, $times);
		}

		if (empty($opening_hours)) {
			return;
		}

		$opening_days = [];
		foreach ($opening_hours as $day => $time) {
			$opening_days[] = $day . ' ' . $time;
		}

		$entity['openingHours'] = $opening_days;
	}
}
