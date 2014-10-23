<?php

namespace filemakerBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class filemakerController extends Controller {

	/**
	 * page d'accueil
	 */
	public function indexAction() {
		$data = array();
		$data['name'] = "anon.";
		return $this->render('filemakerBundle:pages:homepage.html.twig', $data);
	}


	/**
	 * Affichage de la barre de navigation
	 */
	public function navbarAction() {
		$data = array();
		return $this->render('filemakerBundle:menus:navbar.html.twig', $data);
	}

}
