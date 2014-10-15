<?php
// filemakerBundle/services/filemaker.php

namespace filemakerBundle\services;

use Symfony\Component\DependencyInjection\ContainerInterface;

class filemaker {

	protected $data = array();
	protected $container;

	public function __construct(ContainerInterface $container) {
		$this->container = $container;
	}

	public function getName() {
		return "filemaker-service";
	}


}