<?php

namespace filemakerBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class filemakerController extends Controller {

	/**
	 * page d'accueil
	 */
	public function indexAction($name) {
		$data = array();
		$data['name'] = $name;
		return $this->render('filemakerBundle:pages:homepage.html.twig', $data);
	}


	/**
	 * Affichage de la barre de navigation
	 */
	public function navbarAction() {
		$data = array();
		return $this->render('filemakerBundle:blocs:navbar.html.twig', $data);
	}
}
