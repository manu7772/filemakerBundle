<?php

namespace filemakerBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class filemakerController extends Controller {

    public function indexAction($name) {
        return $this->render('filemakerBundle:pages:homepage.html.twig', array('name' => $name));
    }

}
