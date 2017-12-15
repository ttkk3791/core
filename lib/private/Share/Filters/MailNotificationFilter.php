<?php

namespace OC\Share\Filters;

use Zend\InputFilter\Factory as InputFilterFactory;

class MailNotificationFilter {

	/**
	 * @var \Zend\InputFilter\InputFilterInterface
	 */
	private $inputFilter;

	/**
	 * MailNotificationFilter constructor.
	 *
	 * @param array $data
	 */
	public function __construct($data = []) {
		$factory = new InputFilterFactory();
		$this->inputFilter = $factory->createInputFilter([
			'file' => [
				'required' => true,
				'filters' => [
					['name' => 'StringTrim'],
					['name' => 'HtmlEntities'],
				],
			],
			'link' => [
				'required' => true,
				'filters' => [
					['name' => 'StringTrim'],
					['name' => 'StripTags'],
				],
			],
			'toAddress' => [
				'required' => true,
				'filters' => [
					['name' => 'StringTrim'],
					['name' => 'StripTags'],
				],
			],
			'expirationDate' => [
				'required' => true,
				'filters' => [
					['name' => 'StringTrim'],
					['name' => 'StripTags'],
				],
			],
		]);

		$this->inputFilter->setData($data);
	}

	/**
	 * @return string The filtered file name
	 */
	public function getFile() {
		return $this->inputFilter->getValue('file');
	}

	/**
	 * @return string The filtered link value
	 */
	public function getLink() {
		return $this->inputFilter->getValue('link');
	}

	/**
	 * @return string The filtered to email address
	 */
	public function getToAddress() {
		return $this->inputFilter->getValue('toAddress');
	}

	/**
	 * @return string The filtered expiration date
	 */
	public function getExpirationDate() {
		return $this->inputFilter->getValue('expirationDate');
	}
}
