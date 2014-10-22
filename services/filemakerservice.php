<?php
// filemakerBundle/services/filemakerservice.php

namespace filemakerBundle\services;

use Symfony\Component\DependencyInjection\ContainerInterface;

class filemakerservice {

	protected $data = array();
	protected $container;			// ContainerInterface
	protected $APIfm;				// objet FileMaker (accès user logged)
	protected $APIfmSADMIN;			// objet FileMaker (accès Super Admin)
	protected $FMfind;				//
		
	protected $APIfm_paramfile;		// fichier de paramètres de l'API FileMaker

	protected $dbname;				// nom de la base
	protected $dbuser;				// login du Super Admin
	protected $dbpass;				// mot de passe du Super Admin
	protected $dbdescribe;			// description de la bd
	protected $username;			// username
	protected $loguser;				// login user
	protected $logpass;				// pass user

	protected $SA_defined 		= false;	// Super Admin trouvé ou non (boolean)
	protected $user_defined 	= false;	// Super Admin trouvé ou non (boolean)
	protected $SA_logged 		= false;	// Super Admin connecté ou non (boolean)
	protected $user_logged 		= false;	// user connecté ou non (boolean)

	// ATTENTION :
	// AJOUTER le namespace dans l'API FileMaker : namespace filemakerBundle\services;

	public function __construct(ContainerInterface $container) {
		$this->container = $container;
		$this->APIfm_paramfile = __DIR__."/../../../../../app/config/parameters_fm.xml";
		require_once("FileMaker.php");
		if($this->param_findSadmin() === true) {
			echo('fmBDname : '.$this->dbname.'<br />');
			$this->APIfmSADMIN = new FileMaker($this->dbname);
			$this->APIfmSADMIN->setProperty('username', $this->dbuser);
			$this->APIfmSADMIN->setProperty('password', $this->dbpass);
			echo("Login Super Admin : ".$this->dbuser."<br />");
			echo("Passe Super Admin : ".$this->dbpass."<br />");
			$this->setSadminLogg(true);
		}
	}

	/**
	 * Trouve les login et passe du sadmin
	 * dans le fichier app/config/parameters_fm.xml
	 * @param $file / chemin et nom du fichier xml
	 * @param $username / permet de changer le username recherché ('sadmin' par défaut)
	 * @return boolean (true si succès)
	 */
	protected function param_findSadmin($file = null, $username = 'sadmin') {
		$this->setSadminDefined(true);
		$checkServer = array(
			"dbname" => "nom",
			"dbdescribe" => "descriptif",
			);
		$checkUser = array(
			"username" => "username",
			"dbuser" => "login",
			"dbpass" => "pass",
			);
		if($file === null) $file = $this->APIfm_paramfile;
		if(file_exists($file)) {
			$xmldata = simplexml_load_file($file);
			// echo("<pre>");var_dump($xmldata);echo("</pre>");
			// BASE DE DONNÉES
			$findServ = $xmldata->xpath("/FMSERVERS/FMBASE[@default='default']");
			if(count($findServ) > 0) {
				$attr = $findServ[0]->attributes();
				foreach($checkServer as $nomvar => $champ) {
					if(isset($attr[$champ])) {
						$this->$nomvar = $attr[$champ];
					} else {
						$this->$nomvar = null;
						$this->setSadminDefined(false);
					}
				}
			} else $this->setSadminDefined(false);
			// user Super Admin
			$findUser = $xmldata->xpath("/FMSERVERS/FMBASE[@default='default']/user[@username='".$username."']");
			if(count($findUser) > 0) {
				$attr = $findUser[0]->attributes();
				foreach($checkUser as $nomvar => $champ) {
					if(isset($attr[$champ])) {
						$this->$nomvar = $attr[$champ];
					} else {
						$this->$nomvar = null;
						$this->setSadminDefined(false);
					}
				}
			} else $this->setSadminDefined(false);
		} else {
			echo('Fichier de paramétrage FileMaker API non trouvé !!');
			$this->setSadminDefined(false);
		}

		return $this->isSadminDefined();
	}

	/**
	 * log l'utilisateur
	 * @param object User / string $userOrLogin
	 * @param string $pass
	 * @return boolean
	 */
	public function define_user($userOrLogin, $pass = null) {
		if(is_object($userOrLogin)) {
			$this->loguser = $userOrLogin->getFmlogin();
			$this->logpass = $userOrLogin->getFmpass();
		} else {
			$this->loguser = $userOrLogin;
			$this->logpass = $pass;
		}
		$this->setUserDefined(true);

		echo("Login user : ".$this->loguser."<br />");
		echo("Passe user : ".$this->logpass."<br />");
		return $this->isUserDefined();
	}


	/**
	 * log l'utilisateur
	 * @param $login
	 * @param $pass
	 */
	public function log_user($userOrLogin = null, $pass = null) {
		if((($userOrLogin !== null) && ($pass !== null)) || is_object($userOrLogin)) $this->define_user($userOrLogin, $pass);
		if($this->isUserDefined()) {
			$this->APIfm = new FileMaker($this->dbname);
			$this->APIfm->setProperty('username', $this->loguser);
			$this->APIfm->setProperty('password', $this->logpass);
			$this->setUserLogg(true);
		} else $this->setUserLogg(false);

		return $this->isUserLogged();
	}



	// ***********************
	// SETTERS
	// ***********************

	/**
	 * Change défini de Super Admin
	 * @param boolean
	 */
	protected function setSadminDefined($defined) {
		$this->SA_defined = $defined;
	}

	/**
	 * Change defini de user
	 * @param boolean
	 */
	protected function setUserDefined($defined) {
		$this->user_defined = $defined;
	}

	/**
	 * Change connexion statut de Super Admin
	 * @param boolean
	 */
	protected function setSadminLogg($statut) {
		$this->SA_logged = $statut;
	}

	/**
	 * Change connexion statut de user
	 * @param boolean
	 */
	protected function setUserLogg($statut) {
		$this->user_logged = $statut;
	}



	// ***********************
	// GETTERS
	// ***********************

	/**
	 * Nom du service
	 * @return string
	 */
	public function getName() {
		return "filemaker-service";
	}

	/**
	 * Nom de la base
	 * @return string
	 */
	public function getBasename() {
		return $this->dbname;
	}

	/**
	 * username utilisateur
	 * @return string
	 */
	public function getUsername() {
		return $this->username;
	}

	/**
	 * login utilisateur
	 * @return string
	 */
	public function getUserlog() {
		return $this->loguser;
	}

	/**
	 * Super Admin défini ?
	 * @return boolean
	 */
	public function isSadminDefined() {
		return $this->SA_defined;
	}

	/**
	 * user défini ?
	 * @return boolean
	 */
	public function isUserDefined() {
		return $this->user_defined;
	}

	/**
	 * Super Admin connecté ?
	 * @return boolean
	 */
	public function isSadminLogged() {
		return $this->SA_logged;
	}

	/**
	 * user connecté ?
	 * @return boolean
	 */
	public function isUserLogged() {
		return $this->user_logged;
	}

	/**
	 * Renvoie la liste des lieux
	 * @return array
	 */
	public function getLieux() {
		if($this->isUserLogged() === true) {
			// Create FileMaker_Command_Find on layout to search
			$this->FMfind =& $this->APIfm->newFindAllCommand('Lieu_IPAD');
			$this->FMfind->addSortRule('cle', 1, FILEMAKER_SORT_DESCEND);
			$result = $this->FMfind->execute();
			if ($this->APIfm->isError($result)) {
			    $records = array("Error" => $result->getMessage());
			} else {
				$records = $result->getRecords();
			}
			return $records;
		} else return false;
	}


}