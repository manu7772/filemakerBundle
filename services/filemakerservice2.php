<?php
// filemakerBundle/services/filemakerservice2.php

namespace filemakerBundle\services;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
// FileMaker
use \FileMaker;
// YAML
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Exception\ParseException;
// DateTime
use \DateTime;

class filemakerservice2 {

	const API_LOCATION = 		'/../FM/FileMaker.php';			// fichier API FileMaker
	const YML_CONFIG_FILE =		'/../../../../../app/config/fmparameters.yml'; // fichier de configuration YAML
	const SERVICE_NOM =			'filemakerservice2';			// nom du service

	const FM_DATA_OK = 			'_fm_success_init';				// nom de l'attribut d'état du service
	const REQUESTYPE = 			'_request_type';				// nom de l'attribut de type de la requête
	const DEV_SESS_NAME = 		'filemaker_DEV';				//	nom des données de session pour DEV
	const DEF_ERR_SERVER =		'http://localhost';				// serveur FM par défaut en cas d'erreur et donc aucun serveur dispo
	const DEF_SERVERNAME =		'@localhost';					// nom de serveur FM par défaut en cas d'erreur… etc…
	const FM_PARAM_NAME = 		'fmSelect';						// nom en session des paramètres de recherche

	const DEF_VALUE_ON =		true;
	const DEF_VALUE_OFF =		false;

	const SYMB_LIST1 = 			' -&gt; ';
	const SYMB_COLOR9 = 		'#999';
	const SYMB_COLOR_ERROR = 	'red';

	const NOM_DEV_DATA_SHOW =	'devcommentaires';


	protected $container;						// ContainerInterface
	protected $serviceSess;						// Session data
	protected $attributeSess;					// Attributs de session
	protected $sessionServiceNom;				// Nom des données en session

	// état du service ON/OFF
	protected $FM_Operationnel = false;			// état du service (boolean)
	protected $putSessionData = true;			// activeur/désactiveur de mise en session des données

	// bases FM
	protected $fmparameters;					// paramètres de l'API FileMaker
	protected $FMfind = array();				// Résultat de recherche FM
	protected $FMbase;							// Objet FileMaker : 
												// FMbase['serveur']['base']['SA_access']['objet']
												// FMbase['serveur']['base']['US_access']['objet']
	protected $FMbaseUser = null;

	protected $groupScripts;					// liste (array récursif) et groupes de scripts
	protected $listScripts;						// liste simple des scripts

	// Données globales
	protected $SERVER = array(); 				// Liste des serveurs

	protected $currentSERVER; 					// nom du serveur FM en cours
	protected $defaultSERVER; 					// nom du serveur FM par défaut
	protected $currentBASE = array();			// nom de la base en cours
	protected $defaultBASE = array();			// nom de la base par défaut

	protected $globalErrors = array();			// Erreurs du service filemakerservice2
	protected $sourceDescription;				// string : désignation de la source des données de bases

	// paramètres de séletion, tri
	protected $fm_params = array();				// paramètres de recherche, tri

	// USER
	protected $user_defined = false;
	protected $user_logged = false;
	protected $loguser = null;
	protected $logpass = null;

	// DEV et environnement
	protected $environnement;
	protected $DEV = true;
	protected $DEVdata = array();				// Données de développement
	protected $recurs = 0;
	protected $recursMAX = 6;

	public function __construct(ContainerInterface $container) {
		$this->container 			= $container;
		$this->serviceSess 			= $this->container->get('request')->getSession();
		$this->attributeSess		= $this->container->get("request")->attributes;
		$this->fmparameters 		= $this->container->getParameter('fmparameters');
		require_once(__DIR__.self::API_LOCATION);
		// init environnement
		$this->initEnvironnement();
		// switch ON/OFF service
		$this->FM_Operationnel = $this->attributeSess->get(self::FM_DATA_OK);
		if($this->FM_Operationnel === true) {
			$this->getFilemakerserviceDataInSession();
			// $this->echoDev("<h3>CONSTRUCTEUR =&gt; statut ".$this->getName()." : actif</h3>", null, "green");
		} else {
			// $this->echoDev("<h3>CONSTRUCTEUR =&gt; statut ".$this->getName()." : inactif</h3>", null, "red");
		}
		$this->DEVdata[self::NOM_DEV_DATA_SHOW] = array();
		return $this;
	}

	function __destruct() {
		$this->affAllDev();
		// $this->test($this->getDevData);
	}

	public function load_fmservice(FilterControllerEvent $event) {
		// $event->getRequest()->attributes->set(self::REQUESTYPE, $event->getRequestType());
		// $this->__construct($event);
		if(HttpKernelInterface::MASTER_REQUEST === $event->getRequestType()) { // SUB_REQUEST ou MASTER_REQUEST
			$this->container 			= $event;
			$this->serviceSess 			= $this->container->getRequest()->getSession();
			$this->attributeSess		= $this->container->getRequest()->attributes;
			$this->attributeSess->set(self::REQUESTYPE, "Requête PRINCIPALE");
			// $this->echoDev('<h3>LISTENER =&gt; Loading '.self::SERVICE_NOM.' <small>(Requête PRINCIPALE)</small></h3>', null, "green");

			require_once(__DIR__.self::API_LOCATION);
			// init & information en attributs de session (dans "_fm_data_ok")
			$this->FM_Operationnel = $this->initializeService();
			$this->container->getRequest()->attributes->set(self::FM_DATA_OK, $this->FM_Operationnel);
		} else {
			if($this->container instanceOf FilterControllerEvent) {
				$this->container->getRequest()->attributes->set(self::REQUESTYPE, "Requête secondaire");
			}
		}
		$this->vardumpDev($this->getDevData("Chargement"), 'Résultat de l\'analyse :');
	}

	protected function loadYMLparamFile() {
		$yaml = new Parser();
		$file = __DIR__.self::YML_CONFIG_FILE;
		if(file_exists($file)) {
			try {
				$this->fmparameters = $yaml->parse(file_get_contents($file));
			} catch (ParseException $e) {
				die("Parsing du fichier de configuration YAML impossible : %s".$e->getMessage());
			}
		} else {
			// Erreur : fichier de configuration absent
			die('Fichier de configuration absent : '.$file);
		}
	}

	public function reinitService() {
		// initialisation
		$this->initializeService(true);
	}

	/**
	 * Initialise les données du service
	 * dans le fichier app/config/parameters_fm.xml
	 * @param $file / chemin et nom du fichier xml
	 * @param $forceLoad / recharge forcée (si false : récupère les données en session si elles existent)
	 * @return boolean (true si succès)
	 */
	protected function initializeService($forceLoad = false) {
		// désactive la mise en session des données
		$this->disablePutSessionData();
		// charge les paramètres de sélection généraux -> $this->fm_params
		$test = $this->getFromSessionSelects();
		// $this->vardumpDev($test, "Données de paramètres récupérés de session");
		//
		if($this->isDataInSession() === false || $forceLoad === true) {
			$this->setDevData("Chargement", "Scan servers & databases");
			// fichier de paramètres
			$this->loadYMLparamFile();
			$this->SERVER = $this->fmparameters['parameters']['fmparameters'];
			$defaultServerFound = self::DEF_VALUE_OFF;
			$firstServerFound = self::DEF_VALUE_OFF;
			// SERVEURS : début
			if(count($this->SERVER['servers']) > 0) {
				// Descriptions de serveurs trouvées
				foreach($this->SERVER['servers'] as $nomServ => $ssh) {
					$this->SERVER['servers'][$nomServ]['errors'] = array();
					$this->SERVER['servers'][$nomServ]['nom'] = $nomServ;
					if(isset($ssh['ip'])) {
						if($firstServerFound === self::DEF_VALUE_OFF) $firstServerFound = $nomServ;
						if(isset($ssh['default'])) {
							if($ssh['default'] === true && $defaultServerFound === self::DEF_VALUE_OFF) {
								$this->SERVER['servers'][$nomServ]['default'] = self::DEF_VALUE_ON;
								$this->SERVER['default_server'] = $nomServ;
								$this->SERVER['current_server'] = $nomServ;
								$defaultServerFound = self::DEF_VALUE_ON;
							} else {
								$this->SERVER['servers'][$nomServ]['default'] = self::DEF_VALUE_OFF;
							}
						} else {
							$this->SERVER['servers'][$nomServ]['default'] = self::DEF_VALUE_OFF;
						}
						// recherche de BASES
						$listDBServer = $this->getListOfSrvDatabases($ssh['ip']);
						if(is_string($listDBServer)) {
							// Serveur non accessible
							$this->SERVER['servers'][$nomServ]['statut'] = self::DEF_VALUE_OFF;
							$this->SERVER['servers'][$nomServ]['errors'][] = $listDBServer;
						} else if(count($listDBServer) < 1) {
							// Aucune base trouvée sur le serveur
							$this->SERVER['servers'][$nomServ]['statut'] = self::DEF_VALUE_OFF;
							$this->SERVER['servers'][$nomServ]['errors'][] = 'Aucune base sur le serveur.';
							$this->addError('Aucune base sur le serveur.');
						} else {
							// il y a des bases
							$nbbases = 0;
							$defaultBaseFound = self::DEF_VALUE_OFF;
							$firstBaseFound = self::DEF_VALUE_OFF;
							foreach ($ssh['bases'] as $nombase => $base) {
								if(in_array($nombase, $listDBServer)) {
									// base correspondante trouvée sur le serveur
									$this->SERVER['servers'][$nomServ]['bases'][$nombase]['errors'] = array();
									$this->SERVER['servers'][$nomServ]['bases'][$nombase]['statut'] = self::DEF_VALUE_ON;
									$nbbases++;
									if($firstBaseFound === self::DEF_VALUE_OFF) $firstBaseFound = $nombase;
									if(isset($base['default'])) {
										if($base['default'] === true && $defaultBaseFound === self::DEF_VALUE_OFF) {
											$this->SERVER['servers'][$nomServ]['bases'][$nombase]['default'] = self::DEF_VALUE_ON;
											$this->SERVER['servers'][$nomServ]['default_base'] = $nombase;
											$this->SERVER['servers'][$nomServ]['current_base'] = $nombase;
											$defaultBaseFound = self::DEF_VALUE_ON;
										} else {
											$this->SERVER['servers'][$nomServ]['bases'][$nombase]['default'] = self::DEF_VALUE_OFF;
										}
									} else {
										$this->SERVER['servers'][$nomServ]['bases'][$nombase]['default'] = self::DEF_VALUE_OFF;
									}
								} else {
									// aucune base correspondante trouvée sur le serveur
									$this->SERVER['servers'][$nomServ]['errors'][] = 'Base '.$nombase.' non trouvée sur le serveur.';
									$this->SERVER['servers'][$nomServ]['bases'][$nombase]['errors'][] = "Base non trouvée sur le serveur.";
									$this->SERVER['servers'][$nomServ]['bases'][$nombase]['statut'] = self::DEF_VALUE_OFF;
									$this->SERVER['servers'][$nomServ]['bases'][$nombase]['default'] = self::DEF_VALUE_OFF;
								}
							}
							if($nbbases < 1) {
								$this->SERVER['servers'][$nomServ]['default'] = self::DEF_VALUE_OFF;
								$this->SERVER['servers'][$nomServ]['statut'] = self::DEF_VALUE_OFF;
								$this->SERVER['servers'][$nomServ]['errors'][] = 'Aucune base correspondante sur le serveur.';
								$this->addError('aucune base correspondante trouvée sur le serveur.');
							} else {
								$this->SERVER['servers'][$nomServ]['statut'] = self::DEF_VALUE_ON;
								// si aucun serveur par défaut, on prend le premier serveur trouvé
								if($defaultBaseFound === self::DEF_VALUE_OFF) {
									$this->SERVER['servers'][$nomServ]['bases'][$firstBaseFound]['default'] = self::DEF_VALUE_ON;
									$this->SERVER['servers'][$nomServ]['default_base'] = $firstBaseFound;
									$this->SERVER['servers'][$nomServ]['current_base'] = $firstBaseFound;
									$defaultBaseFound = self::DEF_VALUE_ON;
								}
							}
						}
					}
				}
				// récupère le premier serveur valide (car il peut avoir été invalidé !)
				$firstServerFound = self::DEF_VALUE_OFF;
				foreach($this->SERVER['servers'] as $nomServ => $ssh) {
					if($ssh['statut'] === self::DEF_VALUE_ON && $firstServerFound === self::DEF_VALUE_OFF) {
						$firstServerFound = $nomServ;
					}
				}
				if($firstServerFound === self::DEF_VALUE_OFF) {
					// il n'y a plus de serveur valide !
					$this->SERVER['statut'] = self::DEF_VALUE_OFF;
					$this->SERVER['errors'][] = 'Aucun serveur valide. Erreur fatale.';
					$this->SERVER['default_server'] = self::DEF_VALUE_OFF;
					$this->SERVER['current_server'] = self::DEF_VALUE_OFF;
					$defaultServerFound = self::DEF_VALUE_OFF;
				} else {
					// sinon on désigne le premier serveur comme serveur par défaut
					$this->SERVER['servers'][$firstServerFound]['default'] = self::DEF_VALUE_ON;
					$this->SERVER['statut'] = self::DEF_VALUE_ON;
					$this->SERVER['errors'] = array();
					$this->SERVER['default_server'] = $firstServerFound;
					$this->SERVER['current_server'] = $firstServerFound;
					$defaultServerFound = self::DEF_VALUE_ON;
				}
				// Ajout des modèles
				$this->loadCompleteLayouts();
				// Ajout des scripts
				$this->loadCompleteScripts();

				// $this->test($this->SERVER);

				// réactive la mise en session des données
				$this->enablePutSessionData();
				// Sauvegarde en session
				$this->putDataInSession();
			} else {
				$this->SERVER['servers'][self::DEF_SERVERNAME] = self::DEF_ERR_SERVER;
				$this->addError('Description de serveur non trouvée.<br>Selection du serveur par défaut : '.self::DEF_ERR_SERVER." (\"".self::DEF_SERVERNAME."\")");
			}
		} else {
			// chargement des données en session
			$this->getFilemakerserviceDataInSession();
			$this->setDevData("Chargement", "Chargement depuis session");
		}
		// attribue le premier serveur par défaut s'il n'y en a pas eu
		if($this->defaultSERVER === self::DEF_VALUE_OFF) $this->SERVER['servers'][$firstServerFound]['default'] = self::DEF_VALUE_ON;
		return true;
	}

	/**
	 * Enregistre les données de service en session
	 * @param mixed $data - données à enregistrer ($this->SERVER par défaut si null)
	 * @param string $nom - nom des données (self::SERVICE_NOM par défaut)
	 * @return filemakerservice2
	 */
	protected function putDataInSession($force = false) {
		if($this->putSessionData === true || $force === true) {
			// ajout DevData dans SERVER
			$this->SERVER['DEV'] = $this->getDevData();
			// data
			$this->serviceSess->set(self::SERVICE_NOM, $this->SERVER);
			// DEV
			$this->setDevData('Serveur courant', $this->getCurrentSERVER());
			$this->setDevData('Base courante', $this->getCurrentBASE());
			$this->setDevData('Ip courant', $this->getCurrentIP());
			$this->setDevData('SAdmin login', $this->getSAdminCurrentLogin());
			$this->setDevData('SAdmin password', $this->getSAdminCurrentPasse());
			$this->setDevData('Version API FM', $this->getAPIVersion());
			$this->setDevData('Service nom', $this->getName());
			if(method_exists($this, 'getParent')) {
				$this->setDevData('Service parent', $this->getParent());
			}
			$this->serviceSess->set(self::DEV_SESS_NAME, $this->getDevData());
			// $this->vardumpDev($this->getDevData(), "Enregistrement des données de développement");
		}

		return $this;
	}

	/**
	 * Réactive la mise en session des données
	 */
	protected function disablePutSessionData() {
		$this->putSessionData = false;
	}

	/**
	 * Réactive la mise en session des données
	 */
	protected function enablePutSessionData() {
		$this->putSessionData = true;
	}

	/**
	 * Vérifie si les données du service sont en session
	 * @param string $nom - nom des données (self::SERVICE_NOM par défaut)
	 * @return boolean
	 */
	protected function isDataInSession() {
		return count($this->serviceSess->get(self::SERVICE_NOM)) > 0 ? true : false ;
	}

	/**
	 * Récupère et vérifie les modèles de toutes les bases d'un ou plusieurs serveurs $listSRV
	 * @param string/array $listSRV - nom(s) du(es) serveur(s)
	 */
	protected function loadCompleteLayouts($listSRV = null) {
		if($listSRV === null) $listSRV = $this->getListOfServersNames();
			else $listSRV = $this->getServerByNom($listSRV);
		if($listSRV !== false) {
			if(is_string($listSRV)) $listSRV = array($listSRV);
			foreach ($listSRV as $servnom) {
				foreach($this->SERVER['servers'][$servnom]['bases'] as $nombase => $base) {
					$layouts = $this->getLayouts($servnom, $nombase, true);
					if(is_array($layouts)) foreach ($layouts as $key => $layout) {
						$this->SERVER['servers'][$servnom]['bases'][$nombase]['layouts'][$layout] = array();
						$this->SERVER['servers'][$servnom]['bases'][$nombase]['layouts'][$layout]['champs'] = $this->getFields($layout, $nombase, $servnom);
					}
				}
			}
		} else return false;
		return true;
	}

	/**
	 * Récupère et vérifie les scripts de toutes les bases d'un serveur $SERVnom
	 * @param string $SERVnom - nom du serveur
	 * @return array / string si erreur
	 */
	protected function loadCompleteScripts($listSRV = null) {
		if($listSRV === null) $listSRV = $this->getListOfServersNames();
			else $listSRV = $this->getServerByNom($listSRV);
		if($listSRV !== false) {
			if(is_string($listSRV)) $listSRV = array($listSRV);
			foreach ($listSRV as $servnom) {
				foreach($this->SERVER['servers'][$servnom]['bases'] as $nombase => $base) {
					$this->SERVER['servers'][$servnom]['bases'][$nombase]['scripts'] = $this->getScripts($servnom, $nombase, true);
				}
			}
		} else return false;
		return true;
	}

	/**
	 * Liste des éléments requis pour données User (dans XML)
	 * @return array
	 */
	protected function getAttribsFromUser() {
		return array(
			"username" 			=> true,
			"login" 			=> true,
			"passe"				=> true,
			"superadmin"		=> false,
		);
	}

	/**
	 * Récupère les données ou le message d'erreur
	 * @param $result
	 * @return array/string
	 */
	protected function getRecords($result) {
		$fmobj = new FileMaker();
		if ($fmobj->isError($result)) {
		    $records = $result->getMessage();
		} else {
			$records = $result->getRecords();
		}
		return $records;
	}

	/**
	 * Renvoie le nom du serveur (ou false si le serveur n'existe pas)
	 * Peut fonctionner par tableau. Renvoie un tableau des noms valides.
	 * @param string/array $SERVnom - nom du serveur
	 * @return string/array ou false si serveur non existant (uniquement pour $SERVnom fourni en string)
	 */
	protected function getServerByNom($SERVnom) {
		if(is_string($SERVnom)) {
			$feedback = 'string';
			$SERVnom = array(0 => $SERVnom);
		} else if(is_array($SERVnom)) {
			$feedback = 'array';
		} else return false;
		$listOfServersNames = $this->getListOfServersNames();
		$list = array();
		foreach ($SERVnom as $nom) {
			if(in_array($nom, $listOfServersNames)) $list[] = $nom;
		}
		if($feedback == 'string') {
			if(count($list) === 0) return false;
				else return current($list);
		} else {
			return $list;
		}
	}

	/**
	 * définit l'utilisateur
	 * @param object User / string $userOrLogin
	 * @param string $pass
	 * @return boolean
	 */
	protected function define_user($userOrLogin, $pass = null) {
		if(is_object($userOrLogin)) {
			$this->loguser = $userOrLogin->getFmlogin();
			$this->logpass = $userOrLogin->getFmpass();
			$this->setUserDefined(true);
		} else if($pass !== null) {
			$this->loguser = $userOrLogin;
			$this->logpass = $pass;
			$this->setUserDefined(true);
		} else {
			$this->setUserDefined(false);
		}
		return $this->isUserDefined();
	}

	protected function getSAdminCurrentLogin() {
		$server = $this->getCurrentSERVER();
		$base = $this->getCurrentBASE();
		if($server !== false && $base !== false) return $this->SERVER['servers'][$server]['bases'][$this->getCurrentBASE()]['access']['login'];
			else return false;
	}

	protected function getSAdminCurrentPasse() {
		$server = $this->getCurrentSERVER();
		$base = $this->getCurrentBASE();
		if($server !== false && $base !== false) return $this->SERVER['servers'][$server]['bases'][$this->getCurrentBASE()]['access']['passe'];
			else return false;
	}

	protected function getSAdminLogin($server, $base) {
		$server = $this->getCurrentSERVER();
		$base = $this->getCurrentBASE();
		if($server !== false && $base !== false) return $this->SERVER['servers'][$server]['bases'][$base]['access']['login'];
			else return false;
	}

	protected function getSAdminPasse($server, $base) {
		$server = $this->getCurrentSERVER();
		$base = $this->getCurrentBASE();
		if($server !== false && $base !== false) return $this->SERVER['servers'][$server]['bases'][$base]['access']['passe'];
			else return false;
	}

	/**
	 * log l'utilisateur
	 * @param $login - string ou objet User
	 * @param $pass - string ou null
	 * @return boolean
	 */
	public function log_user($userOrLogin = null, $pass = null, $force = false) {
		if($force === true) {
			$userOrLogin = $this->getSAdminCurrentLogin();
			$pass = $this->getSAdminCurrentPasse();
		}
		// $this->setUserLogg(false);
		if((($userOrLogin !== null) && ($pass !== null)) || (is_object($userOrLogin))) {
			if($this->define_user($userOrLogin, $pass) === true) {
				$this->setUserLogg(true);
				$this->FMbaseUser = $this->getNewUserFMobject();
				// var_dump($this->FMbaseUser);
				if(!is_object($this->FMbaseUser)) $this->setUserLogg(false);
			}
		}
		return $this->isUserLogged();
	}

	public function re_log_user() {
		return $this->log_user($this->loguser, $this->logpass);
	}


	// ***********************
	// SETTERS privés
	// ***********************

	/**
	 * SERVER Définit le serveur par défaut
	 * @param string $servnom - nom du serveur
	 * @return boolean
	 */
	protected function setDefaultSERVER($servnom) {
		if(array_key_exists($servnom, $this->SERVER['servers'])) {
			if($this->SERVER['servers'][$servnom]['default'] === self::DEF_VALUE_ON) {
				// annule le serveur par défaut précédent
				foreach($this->SERVER['servers'] as $nom => $serv) {
					$this->SERVER['servers'][$nom]['default'] = self::DEF_VALUE_OFF;
				}
				// Attribue le nouveau serveur par défaut
				$this->SERVER['servers'][$servnom]['default'] = self::DEF_VALUE_ON;
				// relog user
				$this->re_log_user();
				// Sauvegarde en session
				$this->putDataInSession();
			} else return false;
		} else return false;
		return true;
	}

	/**
	 * Vérifie si un serveur existe
	 * @param string $servername - nom du serveur
	 * @return boolean
	 */
	public function serverExists($servername) {
		if(is_string($servername)) return array_key_exists($servername, $this->SERVER['servers']);
			else return false;
	}


	/**
	 * Est-ce que la base existe (et valide) sur le $servnom / ou le serveur courant
	 * @param string $basenom
	 * @param string $servnom - serveur par défaut si non précisé
	 * @return boolean
	 */
	protected function baseExists($basenom, $servnom = null) {
		if($servnom === null) $servnom = $this->getCurrentSERVER();
		if($this->serverExists($servnom) === true && is_string($servnom)) {
			return array_key_exists($basenom, $this->SERVER['servers'][$servnom]['bases']);
		} else return false;
	}

	/**
	 * BASE Définit la base par défaut / selon le serveur courant
	 * @param string $basenom
	 * @return boolean
	 */
	protected function setDefaultBASE($basenom, $servnom = null) {
		if($servnom !== null) {
			$this->setCurrentSERVER($servnom);
		}
		if(in_array($basenom, $this->getListOfBases($this->getCurrentSERVER(), true))) {
			$this->currentBASE = $basenom;
		} else return false;
		// relog user
		$this->re_log_user();
		// Sauvegarde en session
		$this->putDataInSession();
		return true;
	}

	/**
	 * SERVER Rétablit le serveur par défaut d'origine
	 * @return filemakerservice2
	 */
	protected function resetDefaultSERVER() {
		foreach ($this->SERVER as $SERVnom => $SERV) {
			if($SERV['default'] === self::DEF_VALUE_ON) $this->defaultSERVER = $SERVnom;
		}
		// relog user
		$this->re_log_user();
		// Sauvegarde en session
		$this->putDataInSession();
		return $this;
	}

	/**
	 * Change defini de user
	 * @param boolean
	 * @return filemakerservice2
	 */
	protected function setUserDefined($defined = true) {
		if(is_bool($defined)) $this->user_defined = $defined;
			else $this->user_defined = false;
		// Sauvegarde en session
		$this->putDataInSession();
		return $this;
	}

	/**
	 * Change connexion statut de user
	 * @param boolean
	 * @return filemakerservice2
	 */
	protected function setUserLogg($log = true) {
		if(is_bool($log)) $this->user_logged = $log;
			else $this->user_logged = false;
		// Sauvegarde en session
		$this->putDataInSession();
		return $this;
	}



	// ***********************
	// SETTERS publics
	// ***********************

	/**
	 * SERVER Définit le serveur courant
	 * @param string $SERVnom (si null, définit le serveur par défaut)
	 * @return string (nom du serveur) ou false si inconnu
	 */
	public function setCurrentSERVER($SERVnom = null) {
		$SERVnom = $this->getServerByNom($SERVnom);
		if($SERVnom !== false) {
			// annule le serveur courant
			foreach ($this->SERVER['servers'] as $nom => $serv) {
				$this->SERVER['servers'][$nom]['current'] = self::DEF_VALUE_OFF;
			}
			// définit le nouveau serveur
			$this->SERVER['servers'][$SERVnom]['current'] = self::DEF_VALUE_ON;
			// relog user
			$this->re_log_user();
			// Sauvegarde en session
			$this->putDataInSession();
			return $SERVnom;
		} else {
			$this->addError("Changement serveur : ".$SERVnom." non trouvé.");
			return false;
		}
	}

	/**
	 * BASE Définit la base courante
	 * @param string $servnom (si null, définit le serveur par défaut)
	 * @param string $nombase (si null, définit la base par défaut)
	 * @return filemakerservice2
	 */
	public function setCurrentBASE($nombase = null, $servnom = null) {
		if($servnom === null) {
			$servnom = $this->getCurrentSERVER();
		} else {
			$servnom = $this->setCurrentSERVER($servnom);
		}
		if($servnom !== false) {
			if($nombase === null) $nombase = $this->getDefaultBASE();
			if(in_array($nombase, $this->getListOfBases($servnom, true))) {
				// annule la base courante (du serveur défini ou courant)
				foreach($this->SERVER['servers'][$servnom]['bases'] as $nom => $base) {
					$this->SERVER['servers'][$servnom]['bases'][$nom]['current'] = self::DEF_VALUE_OFF;
				}
				// définit la nouvelle base
				$this->SERVER['servers'][$servnom]['bases'][$nombase]['current'] = self::DEF_VALUE_ON;
				$this->SERVER['servers'][$servnom]['current_base'] = $nombase;
				// relog user
				$this->re_log_user();
				// Sauvegarde en session
				$this->putDataInSession();
				return $nombase;
			} else return false;
		} else return false;
	}

	/**
	 * Définit le modèle courant
	 * @param string $model - nom du modèle (layout)
	 * @param string $nombase - nom de la base (optionnel)
	 * @param string $servnom - nom du serveur (optionnel)
	 * @return boolean true / string erreur message
	 */
	public function setCurrentModel($model, $nombase = null, $servnom = null) {
		// var_dump($model);
		if($servnom !== null) {
			if($this->setCurrentSERVER($servnom) === false) return 'Serveur '.$servnom." absent. Impossible d'accéder aux données";
		}
		if($nombase !== null) {
			if($this->setCurrentBASE($nombase) === false) return 'Base '.$nombase." absente. Impossible d'accéder aux données";
		}
		if(!$this->layoutExists($model)) return "Modèle \"".$model."\" absent. Impossible d'accéder aux données.";
		if(!$this->isUserLogged() === true) return "Utilisateur non connecté.";
		if(!is_object($this->FMbaseUser)) return "Objet FileMaker non initialisé.";
		// OK le modèle existe et est accessible
		return array('nom' => $model);
	}

	// ***********************
	// GETTERS privés
	// ***********************

	/**
	 * Liste des recherches de databases du serveur $serveur
	 * array simple des noms : [0] => nom
	 * @param array / string si erreur
	 */
	protected function getListOfSrvDatabases($ServIP = null) {
		$fm = new FileMaker();
		$fm->setProperty('hostspec', $ServIP);
		$records = $fm->listDatabases();
		if ($fm->isError($records)) {
			$records = "Erreur en accès serveur ".$ServIP;
		}
		// $this->vardumpDev($records, "Liste des bases trouvées sur le srveur ".$ServIP);
		return $records;
	}

	/**
	 * Renvoie le hostspec du serveur courant (ip)
	 * @return string / false si erreur
	 */
	protected function getCurrentIP($servnom = null) {
		if($servnom === null) $servnom = $this->getCurrentSERVER();
		if($this->serverExists($servnom)) {
			$r = $this->SERVER['servers'][$servnom]['ip'];
		} else $r = false;
		return $r;
	}

	/**
	 * Renvoie un nouvel objet FileMaker connecté en Sadmin
	 * @param string $basenom
	 * @param string $servnom
	 * @return FileMaker
	 */
	protected function getNewSadminFMobject($servnom = null, $basenom = null) {
		if($servnom === null) {
			$servnom = $this->getCurrentSERVER();
			$IP = $this->getCurrentIP($servnom);
		} else if($this->serverExists($servnom)) {
			$IP = $this->getCurrentIP($servnom);
		} else return false;
		if($basenom === null) {
			$basenom = $this->getCurrentBASE($servnom);
		} else if(!$this->baseExists($basenom)) {
			return false;
		}
		$FMbase = new FileMaker();
		$FMbase->setProperty('hostspec', $IP);
		$FMbase->setProperty('database', $basenom);
		$FMbase->setProperty('username', $this->getSAdminLogin($servnom, $basenom));
		$FMbase->setProperty('password', $this->getSAdminPasse($servnom, $basenom));
		return $FMbase;
	}

	/**
	 * Renvoie un nouvel objet FileMaker connecté en User
	 * @param string $login
	 * @param string $passe
	 * @param string $basenom
	 * @param string $servnom
	 * @return FileMaker ou false si erreur
	 */
	protected function getNewUserFMobject($login = null, $passe = null, $servnom = null, $basenom = null) {
		if($login === null) $login = $this->loguser;
		if($passe === null) $passe = $this->logpass;
		if($this->isUserLogged() === true) {
			if($servnom === null) {
				$servnom = $this->getCurrentSERVER();
				$IP = $this->getCurrentIP($servnom);
			} else if($this->serverExists($servnom)) {
				$IP = $this->getCurrentIP($servnom);
			} else return false;
			if($basenom === null) {
				$basenom = $this->getCurrentBASE($servnom);
			} else if(!$this->baseExists($basenom)) {
				return false;
			}
			$FMbase = new FileMaker();
			$FMbase->setProperty('hostspec', $IP);
			$FMbase->setProperty('database', $basenom);
			$FMbase->setProperty('username', $login);
			$FMbase->setProperty('password', $passe);
			return $FMbase;
		} else {
			return false;
		}
	}



	// ***********************
	// GETTERS publics
	// ***********************

	/**
	 * Nom du service
	 * @return string
	 */
	public function getName() {
		return self::SERVICE_NOM;
	}

	public function getGlobalData($forceload = false) {
		if($forceload === true) $this->getFilemakerserviceDataInSession();
		return $this->SERVER;
	}

	/**
	 * SERVER Renvoie le nom du serveur courant
	 * @return string ou false si aucun
	 */
	public function getCurrentSERVER() {
		if($this->SERVER['statut'] === self::DEF_VALUE_ON) {
			return $this->SERVER['current_server'];
		} else return false;
	}

	/**
	 * SERVER Renvoie le serveur par défaut
	 * @return string ou false si aucun
	 */
	protected function getDefaultSERVER() {
		if($this->SERVER['statut'] === self::DEF_VALUE_ON) {
			return $this->SERVER['default_server'];
		} else return false;
	}

	/**
	 * BASE Nom de la base courante (du serveur courant si serveur non précisé)
	 * @param string $SERVnom
	 * @return string ou false si aucune
	 */
	public function getCurrentBASE($servnom = null) {
		if($servnom === null) $servnom = $this->getCurrentSERVER();
		if($servnom !== false) {
			return $this->SERVER['servers'][$servnom]['current_base'];
		} else return false;
	}

	/**
	 * BASE Nom de la base par défaut (du serveur courant si serveur non précisé)
	 * @param string $servnom
	 * @return string ou false si aucune
	 */
	protected function getDefaultBASE($servnom = null) {
		if($servnom === null) $servnom = $this->getCurrentSERVER();
		if($servnom !== false) {
			return $this->SERVER['servers'][$servnom]['default_base'];
		} else return false;
	}

	/**
	 * user défini ?
	 * @return boolean
	 */
	public function isUserDefined() {
		return $this->user_defined;
	}

	/**
	 * user connecté ?
	 * @return boolean
	 */
	public function isUserLogged() {
		return $this->user_logged;
	}

	/**
	 * Récupère les données du service placées en session
	 * Renvoie false si aucun serveur n'est disponible
	 * @param string $nom - nom des données (par défaut : valeur de self::SERVICE_NOM)
	 * @return boolean
	 */
	public function getFilemakerserviceDataInSession() {
		$this->SERVER = $this->serviceSess->get(self::SERVICE_NOM);
		return is_array($this->SERVER);
	}

	/**
	 * liste des noms de serveurs
	 * @param boolean $onlyValids - true pour ne récupérer que les serveurs valides
	 * @return array
	 */
	public function getListOfServersNames($onlyValids = true) {
		$list = array();
		foreach ($this->SERVER['servers'] as $servnom => $serveur) {
			if($serveur['statut'] === self::DEF_VALUE_ON || $onlyValids === false) $list[] = $servnom;
		}
		return $list;
	}

	/**
	 * Vérifie si un script existe pour la base courante
	 * @param string $scriptname - nom du script
	 * @return boolean
	 */
	public function scriptExists($scriptname, $basenom = null, $servnom = null) {
		if($servnom === null) $servnom = $this->getCurrentSERVER();
		if($basenom === null) $basenom = $this->getCurrentBASE($servnom);
		return in_array($scriptname, $this->SERVER['servers'][$servnom]['bases'][$basenom]['scripts']);
	}

	/**
	 * Vérifie si un modèle existe pour la base courante
	 * @param string $layoutname - nom du layout
	 * @return boolean
	 */
	public function layoutExists($layoutname, $basenom = null, $servnom = null) {
		if($servnom === null) $servnom = $this->getCurrentSERVER();
		if($basenom === null) $basenom = $this->getCurrentBASE($servnom);
		return array_key_exists($layoutname, $this->SERVER['servers'][$servnom]['bases'][$basenom]['layouts']);
	}

	/**
	 * Renvoie la liste des bases d'un serveur $servnom
	 * @param string $servnom - nom du serveur (ou serveur par défaut si null)
	 * @param string $statut - statut recherché : "valids" / "unvalids" / "scan"
	 * @return array ou false si aucune ou serveur inexistant
	 */
	public function getListOfBases($servnom = null, $onlyValids = true) {
		if($servnom === null) $servnom = $this->getCurrentSERVER();
		$r = $this->SERVER['servers'][$servnom]['bases'];
		$bases = array();
		foreach ($r as $key => $base) {
			if($base['statut'] === self::DEF_VALUE_ON || $onlyValids === false) $bases[] = $key;
		}
		return $bases;
	}

	/**
	 * Renvoie la liste des scripts
 	 * @param string $servnom - nom du serveur (ou serveur par défaut si null)
	 * @param string $basenom - nom de la base (ou base par défaut si null)
	 * @param boolean $forceload
	 * @param boolean $trad - false : résultat en liste simple / true : résultat en groupes (tableau récursif)
	 * @return array / string
	 */
	public function getScripts($servnom = null, $basenom = null, $forceLoad = false, $trad = false) {
		if($servnom === null) $servnom = $this->getCurrentSERVER();
		if($basenom === null) $basenom = $this->getCurrentBASE();
		if($forceLoad === true) {
			// scan
			// Create FileMaker_Command_Find on layout to search
			$this->FMbase = $this->getNewSadminFMobject($servnom, $basenom);
			$this->FMfind = $this->FMbase->listScripts();
			if ($this->FMbase->isError($this->FMfind)) {
				$records = "Accès non autorisé.";
			} else {
				$records = $this->FMfind;
			}
		} else {
			// Chargement depuis $SERVER
			if(isset($this->SERVER['servers'][$servnom]['bases'])) {
				$records = $this->SERVER['servers'][$servnom]['bases'][$basenom]['scripts'];
			} else $records = array();
			if(count($records) < 1) $records = "Aucun script trouvé.";
		}
		$this->sortGroupScripts($records);
		$this->sortListeScripts($records);
		// $this->DEV = true;
		$this->vardumpDev($this->groupScripts, "Scripts groupes");
		$this->vardumpDev($this->listScripts, "Scripts liste simple");
		// $this->DEV = false;
		if($trad === true) {
			// Renvoie $this->groupScripts
			$records = $this->groupScripts;
		} else {
			// Renvoie $this->listScripts
			$records = $this->listScripts;
		}
		return $records;
	}

	/**
	 * Transforme le résultat de getScripts en présentation array (liste simple)
	 * @return array
	 */
	protected function sortListeScripts($list) {
		$this->listScripts = array();
		if(is_array($list)) {
			foreach ($list as $key => $value) {
				if(!preg_match('#^('.chr(238).chr(128).chr(129).'|'.chr(238).chr(128).chr(130).')#', $value)) {
					$this->listScripts[] = $value;
				}
			}
		} else $this->listScripts = array();
		return $this->listScripts;
	}

	/**
	 * Transforme le résultat de getScripts en présentation en groupes (récursifs)
	 * @return array
	 */
	protected function sortGroupScripts($list) {
		if(is_array($list)) {
			$this->groupScripts = $list;
			reset($this->groupScripts);
			$data = $this->reTradScripts();
			$this->groupScripts = $data;
		} else $this->groupScripts = array();
		return $this->groupScripts;
	}
	/**
	 * Fonction récursive de tri des scripts - appelée par $this->sortGroupScripts
	 * @return array
	 */
	protected function reTradScripts() {
		$data = array();
		do {
			$nom = current($this->groupScripts);
			$nom2 = next($this->groupScripts);
			if(preg_match('#^'.chr(238).chr(128).chr(130).'#', $nom)) {
				// echo('Retour…<br>');
				return $data;
			}
			if(preg_match('#^'.chr(238).chr(128).chr(129).'#', $nom)) {
				// echo(preg_replace('#^'.chr(238).chr(128).chr(129).'#', "Groupe_", $nom)."<br>");
				$data[preg_replace('#^'.chr(238).chr(128).chr(129).'#', "", $nom)] = $this->reTradScripts();
			} else {
				$data[] = $nom;
				// echo(' - '.$nom.'<br>');
			}
		} while(is_string($nom2));
		return $data;
	}

	// /**
	//  * Renvoie la liste des modèles (en récursif)
	//  * @return array
	//  */
	// public function getLayouts($servnom = null, $basenom = null, $forceLoad = false) {
	// 	if($servnom === null) $servnom = $this->getCurrentSERVER();
	// 	if($basenom === null) $basenom = $this->getCurrentBASE();
	// 	if($forceLoad === true) {
	// 		// scan
	// 		$this->FMbase = $this->getNewSadminFMobject($servnom, $basenom);
	// 		$this->FMfind = $this->FMbase->listLayouts();
	// 		if ($this->FMbase->isError($this->FMfind)) {
	// 		    $records = "Accès non autorisé.";
	// 		} else {
	// 			$records = $this->FMfind;
	// 		}
	// 	} else {
	// 		// Chargement depuis $SERVER
	// 		if(isset($this->SERVER['servers'][$servnom]['bases'])) {
	// 			$records = $this->SERVER['servers'][$servnom]['bases'][$basenom]['layouts'];
	// 		} else $records = array();
	// 		if(count($records) < 1) $records = "Aucun modèle trouvé.";
	// 	}
	// 	// nettoyage…
	// 	// if(is_array($records)) {
	// 	// 	$record2 = array();
	// 	// 	foreach ($records as $key => $value) {
	// 	// 		if(trim($value) !== "") $record2[] = $value;
	// 	// 	}
	// 	// 	$records = $record2;
	// 	// 	unset($record2);
	// 	// }
	// 	return $records;
	// }

	/**
	 * Renvoie la liste des modèles (en récursif)
	 * @return array
	 */
	public function getLayouts($SERVnom = null, $BASEnom = null, $forceLoad = false) {
		if($SERVnom === null) $SERVnom = $this->getCurrentSERVER();
		if($BASEnom === null) $BASEnom = $this->getCurrentBASE($SERVnom);

		if($forceLoad === true) {
			// scan
			$this->FMbase = $this->getNewSadminFMobject($SERVnom, $BASEnom);
			$this->FMfind = $this->FMbase->listLayouts();
			if ($this->FMbase->isError($this->FMfind)) {
			    $records = "Accès non autorisé.";
			} else {
				$records = $this->FMfind;
			}
		} else {
			// Chargement depuis $SERVER
			$records = $this->SERVER['servers'][$SERVnom]['bases'][$BASEnom]['layouts'];
			if(count($records) < 1) $records = "Aucun modèle trouvé.";
		}
		return $records;
	}

	// /**
	//  * Renvoie la liste des champs de $layout
	//  * @param string $layout - nom du modèle
	//  * @param string $basenom - nom de la base (optionnel)
	//  * @param string $servnom - nom du serveur (optionnel)
	//  * @return array
	//  */
	// public function getFields($layout, $basenom = null, $servnom = null) {
	// 	if($servnom === null) $servnom = $this->getDefaultSERVER();
	// 	if($basenom === null) $basenom = $this->getDefaultBASE();
	// 	// $this->setCurrentBASE($basenom, $servnom);
	// 	$this->FMbase = $this->getNewSadminFMobject($servnom, $basenom);
	// 	$lay = $this->FMbase->getLayout($layout);
	// 	$result = $lay->listFields();
	// 	// if(count($result) < 1) $result = "Aucun champ trouvé dans le modèle ".$layout.".";
	// 	return $result;
	// }

	/**
	 * Renvoie la liste des champs de $layout
	 * @param string $layout - nom du modèle
	 * @param string $BASEnom - nom de la base (optionnel)
	 * @param string $SERVnom - nom du serveur (optionnel)
	 * @return array
	 */
	public function getFields($layout, $BASEnom = null, $SERVnom = null) {
		if($SERVnom === null) $SERVnom = $this->getCurrentSERVER();
		if($BASEnom === null) $BASEnom = $this->getCurrentBASE($SERVnom);
		
		if(isset($this->SERVER['servers'][$SERVnom]['bases'][$BASEnom]['layouts'])) {
			if(array_key_exists($layout, $this->SERVER['servers'][$SERVnom]['bases'][$BASEnom]['layouts'])) {
				// if($this->setCurrentBASE($BASEnom, $SERVnom) !== false) {
					$this->FMbase = $this->getNewSadminFMobject($SERVnom, $BASEnom);
					$lay = $this->FMbase->getLayout($layout);
					if ($this->FMbase->isError($lay)) {
						$result = $lay->getMessage();
						// $result = "Accès non autorisé.";
					} else if(is_object($lay)) {
						$result = $lay->listFields();
					}
					// $result = $lay->getFields();
					// if(is_array($result) && count($result) < 1) $result = "Aucun champ trouvé dans le modèle ".$layout.".";
				// } else $result = "Erreur au changement de base ou serveur.";
			} else $result = "Ce modèle n'existe pas.";
		} else $result = "Base non reconnue.";
		return $result;
	}

	/**
	 * Renvoie la liste DÉTAILLÉE des champs de $layout
	 * @param string $layout - nom du modèle
	 * @param string $BASEnom - nom de la base (optionnel)
	 * @param string $SERVnom - nom du serveur (optionnel)
	 * @return array
	 */
	public function getDetailFields($layout, $BASEnom = null, $SERVnom = null) {
		if($SERVnom === null) $SERVnom = $this->getCurrentSERVER();
		if($BASEnom === null) $BASEnom = $this->getCurrentBASE($SERVnom);
		
		if(isset($this->SERVER['servers'][$SERVnom]['bases'][$BASEnom]['layouts'])) {
			if(array_key_exists($layout, $this->SERVER['servers'][$SERVnom]['bases'][$BASEnom]['layouts'])) {
				if($this->setCurrentBASE($BASEnom, $SERVnom) !== false) {
					$lay = $this->FMbaseUser->getLayout($layout);
					// $result = $lay->listFields();
					$result['fields'] = $lay->getFields();
					$result['rel_fields'] = $lay->listRelatedSets();
					$result['layout'] = $lay->getName();
					$result['base'] = $lay->getDatabase();
					if(count($result) < 1) $result = "Aucun champ trouvé dans le modèle ".$layout.".";
				} else $result = "Erreur au changement de base ou serveur.";
			} else $result = "Ce modèle n'existe pas.";
		} else $result = "Base non reconnue.";
		return $result;
	}

	/**
	 * Renvoie la liste des données demandées dans le modèle $model
	 * @param string $model - nom du modèle
	 * @return array ou string si erreur
	 */
	public function getData($data) {
		// https://fmhelp.filemaker.com/docs/13/fr/fms13_cwp_php.pdf --> p.45

		// public function getData($model, $select = null, $BASEnom = null, $SERVnom = null) {
		// pour $data :
		// server 		= nom_du_serveur
		// base 		= nom_de_la_base
		// modele 		= nom_du_modele
		// column 		= nom_de_la_rubrique
		// value 		= valeur_de_recherche
		// order 		= ordre_de_tri ("ASC" ou "DESC")
		// reset 		= 1 ou 0 (1 pour réinitialiser)
		// echo('<pre>');
		// var_dump($data);
		// echo('</pre>');
		$model = $this->setCurrentModel($data['modele'], $data['base'], $data['server']);
		// erreur ?
		if(is_string($model)) return $model;

		// Create FileMaker_Command_Find on layout to search
		$this->FMfind = $this->FMbaseUser->newFindCommand($model['nom']);

		// reset select
		// if(isset($data['reset'])) if($data['reset'] === "1") $this->resetAllSelect();

		if(isset($data['search'])) {
			if(count($data['search']) > 0) {
				$this->FMfind = $this->FMbaseUser->newCompoundFindCommand($model['nom']);
				$count = 1;
				foreach ($data['search'] as $key => $value) {
					$findreq = $this->FMbaseUser->newFindRequest($model['nom']);
					if(!isset($value['operator'])) $value['operator'] = null;
					switch ($value['operator']) {
						case '>': $op = ">"; break;
						case '<': $op = "<"; break;
						case '=': $op = "="; break;
						// case '!': $op = "!"; break;
						default: $op = ""; break;
					}
					// echo("<pre>");
					// var_dump($value);
					// echo("</pre><br><br>");
					$findreq->addFindCriterion($value['column'], $op.$value['value']);
					if($value['operator'] == '!') {
						$findreq->setOmit(true);
						// echo("Exclude : ".$value['column']." = ".$value['value']."<br>");
					}
					$this->FMfind->add($count++, $findreq);
				}
			}
		}

		if(isset($data['sort'])) {
			if(count($data['sort']) > 0) foreach ($data['sort'] as $key => $value) {
				if(strtoupper($value['way']) === "ASC") $way = FILEMAKER_SORT_ASCEND;
					else $way = FILEMAKER_SORT_DESCEND;
				$this->FMfind->addSortRule($value['column'], $key, $way);
			}
		}

		return $this->getRecords($this->FMfind->execute());
	}

	/**
	 * Renvoie la version de l'API
	 * @return string (?)
	 */
	public function getAPIVersion() {
		if(is_object($this->FMbase)) return $this->FMbase->getAPIVersion();
		else return 'Version indisponible';
	}


	// ***********************
	// SELECTION & TRI
	// ***********************
	// chaque lot de paramètre est désigné par un nom ($nom)
	// si $nom = null, on lui attribue le nom du modèle courant :
	// nom_du_serveur::nom_de_la_base::nom_du_modèle
	// ex. : "Géodem mac-mini::GEODIAG_SERVEUR::Projet_Liste"

	/**
	 * Réinitialise tous les éléments de recherche et de tri de $nom
	 * @param string $nom - nom du modèle (layout)
	 */
	public function resetAllSelect($nom = null) {
		$nom = $this->getNomCurrentSelect($nom);
		$this->getFromSessionSelects();
		if(isset($this->fm_params[$nom])) {
			$this->fm_params[$nom] = null;
			unset($this->fm_params[$nom]);
		}
		$this->PutInSessionSelects();
		return $this;
	}

	/**
	 * Renvoie les données de séletion du lot $nom
	 * @param string $nom - nom du lot
	 * @return array ou false
	 */
	protected function getAllSelectParams($nom = null) {
		$nom = $this->getNomCurrentSelect($nom);
		$this->getFromSessionSelects();
		if(isset($this->fm_params[$nom])) return $this->fm_params[$nom];
			else return false;
	}

	/**
	 * 
	 * 
	 * @return filemakerservice2
	 */
	protected function addSearch($column, $value, $nom = null) {
		$nom = $this->getNomCurrentSelect($nom);
		$this->getFromSessionSelects();
		$this->initNewSelectNom($nom);

		$this->fm_params[$nom]['search'][$column] = $value;

		$this->PutInSessionSelects();
		return $this;
	}

	/**
	 * 
	 * 
	 * @return filemakerservice2
	 */
	protected function addSort($column, $value, $nom = null) {
		$nom = $this->getNomCurrentSelect($nom);
		$this->getFromSessionSelects();
		$this->initNewSelectNom($nom);

		$this->fm_params[$nom]['sort'][$column] = $value;

		$this->PutInSessionSelects();
		return $this;
	}

	/**
	 * Renvoie le nom complet du lot de paramètres de sélection
	 * @param string $nom - nom du layout (ou autre si besoin)
	 * @return string
	 */
	protected function getNomCurrentSelect($nom = null) {
		// si $nom non défini, on prend le nom du layout courant
		if($nom === null) $nom = $this->getCurrentModel();
		// si nom est complet (3 modules séparés par "::"), on le garde tel quel
		if(count(explode("::", $nom)) === 3) $r = $nom;
			else $r = $this->getCurrentSERVER()."::".$this->getCurrentBASE()."::".$nom;
		return $r;
	}

	/**
	 * Charge les paramètres de sélection depuis la session
	 * @return array
	 */
	protected function getFromSessionSelects() {
		$this->fm_params = $this->serviceSess->get(self::FM_PARAM_NAME);
		return $this->fm_params;
	}

	/**
	 * Sauve les paramètres de sélection dans la session
	 * @return filemakerservice2
	 */
	protected function PutInSessionSelects() {
		$this->serviceSess->set(self::FM_PARAM_NAME, $this->fm_params);
		return $this;
	}

	/**
	 * initialise des paramètres de sélection pour un $nom
	 * @param string $nom - nom du lot de sélection
	 * @param boolean $force - écrase si existante (false par défaut)
	 * @return filemakerservice2 ou false si déjà existante et n'a pas été effacée
	 */
	protected function initNewSelectNom($nom, $force = false) {
		if(isset($this->fm_params[$nom]) && $force !== true) return false;
		$this->fm_params[$nom] = array();
		$this->fm_params[$nom]['search'] = array();
		$this->fm_params[$nom]['order'] = array();
		return $this;
	}

	/**
	 * 
	 * 
	 */
	public function xxxxxx() {
		//
	}

	// ***********************
	// ERRORS & DEV
	// ***********************

	/**
	 * Ajoute une erreur / insère la date/heure automatiquement
	 * @param string $message --> message d'erreur
	 * @return filemakerservice2
	 */
	protected function addError($message) {
		if(!is_string($message)) return false;
		return $this->addErrors(array($message));
	}


	/**
	 * Ajoute une erreur & insère la date/heure automatiquement
	 * @param array $messages --> array de messages d'erreur
	 * @param boolean $putFlash --> insère également en données flashbag si true (true par défaut)
	 * @return filemakerservice2
	 */
	protected function addErrors($messages, $putFlash = true) {
		$time = new DateTime();
		if(is_string($messages)) $messages = array($messages);
		foreach ($messages as $message) {
			// ajoute un point à la fin du message s'il n'y en a pas
			if($message[strlen($message) - 1] !== ".") $message = $message.".";
			// et une majuscule en début de ligne…
			$message = ucfirst($message);
			$this->globalErrors[] = array($message, $time);
			if($putFlash === true) {
				$this->serviceSess->getFlashBag()->add("FMerror", $message." (".$time->format("H:i:s - d/m/Y").")");
			}
		}
		return $this;
	}

	/**
	 * Renvoie la liste des erreurs
	 * @return array globalErrors
	 */
	public function getErrors() {
		return $this->globalErrors;
	}

	/**
	 * Affiche les erreurs (Boostrap required)
	 */
	protected function affErrors() {
		if($this->isDev()) {
			echo("<br /><div class='container'><table class='table table-bordered table-hover table-condensed'>");
			foreach ($this->globalErrors as $key => $error) {
				echo("	<tr>");
				echo("		<td>".$error[0]."</td>");
				echo("		<td>".$error[1]->format("H:i:s - Y/m/d")."</td>");
				echo("	</tr>");
			}
			echo("</table></div><br /><br />");
		}
	}


	/****************************************/
	/*** AUTRES MÉTHODES DE DÉVELOPPEMENT
	/****************************************/

	/**
	 * initialise les données d'environnement
	 */
	protected function initEnvironnement() {
		$this->environnement = $this->container->get('kernel')->getEnvironment();
		$this->setDevData("Environnement", $this->environnement);
		if($this->environnement === "prod") $this->DEV = false;
			else $this->DEV = true;
		// $this->DEV = false;
	}

	/**
	 * Renvoie true si l'environnement est en DEV / sinon false
	 * @return boolean
	 */
	protected function isDev() {
		return $this->DEV;
	}

	/**
	 * Renvoie true si l'environnement est en PROD / sinon false
	 * @return boolean
	 */
	protected function isProd() {
		return !$this->DEV;
	}

	/**
	 * Ajoute des données de développement
	 * @param string $nom
	 * @param mixed $data
	 */
	protected function setDevData($nom, $data) {
		$this->DEVdata[$nom] = $data;
	}

	/**
	 * Renvoie des données de développement
	 * Si $nom n'est pas renseigné, renvoie toutes les données de développement
	 * @param string $nom = null
	 * @param mixed $data
	 */
	protected function getDevData($nom = null) {
		if(is_string($nom)) {
			if(isset($this->DEVdata[$nom])) return $this->DEVdata[$nom];
				else return null;
		} else return $this->DEVdata;
	}

	/**
	 * affiche le contenu de $data (récursif)
	 * @param mixed $data
	 */
	protected function getPreData($data, $nom = null) {
		$texte = "";
		$this->recurs++;
		if($this->recurs <= $this->recursMAX) {
			$style = " style='margin:4px 0px 8px 20px;padding-left:4px;border-left:1px solid #666;'";
			$istyle = " style='color:#999;font-style:italic;'";
			if(is_string($nom)) {
				$affNom = "[\"".$nom."\"] ";
			} else if(is_int($nom)) {
				$affNom = "[".$nom."] ";
			} else {
				$affNom = "[type ".gettype($data)."] ";
				$nom = null;
			}
			switch (strtolower(gettype($data))) {
				case 'array':
					$texte .= ("<div".$style.">");
					$texte .= ($affNom."<i".$istyle.">".gettype($data)."</i> (".count($data).")");
					foreach($data as $nom2 => $dat2) $texte .= $this->getPreData($dat2, $nom2);
					$texte .= ("</div>");
					break;
				case 'object':
					$tests = array('id', 'nom', 'dateCreation');
					$tab = array();
					foreach($tests as $nomtest) {
						$method = 'get'.ucfirst($nomtest);
						if(method_exists($data, $method)) {
							$val = $data->$method();
							// if($val instanceOf DateTime) $val = $val->format("Y-m-d H:i:s");
							$tab[$nomtest] = $val;
						}
					}
					if($data instanceOf DateTime) $affdata = $data->format("Y-m-d H:i:s");
						else $affdata = '';
					$texte .= ("<div".$style.">");
					$texte .= ($affNom." <i".$istyle.">".gettype($data)." > ".get_class($data)."</i> ".$affdata); // [ ".implode(" ; ", $tab)." ]
					foreach($tab as $nom2 => $dat2) $this->getPreData($dat2, $nom2);
					$texte .= ("</div>");
					break;
				case 'string':
				case 'integer':
					$texte .= ("<div".$style.">");
					$texte .= ($affNom." <i".$istyle.">".gettype($data)."</i> \"".$data."\"");
					$texte .= ("</div>");
					break;
				case 'boolean':
					$texte .= ("<div".$style.">");
					if($data === true) $databis = 'true';
						else $databis = 'false';
					$texte .= ($affNom." <i".$istyle.">".gettype($data)."</i> ".$databis);
					$texte .= ("</div>");
					break;
				case 'null':
					$texte .= ("<div".$style.">");
					$texte .= ($affNom." <i".$istyle.">type ".strtolower(gettype($data))."</i> ".gettype($data));
					$texte .= ("</div>");
					break;
				default:
					$texte .= ("<div".$style.">");
					$texte .= ($affNom." <i".$istyle.">".gettype($data)."</i> ");
					$texte .= ("</div>");
					break;
			}
		}
		$this->recurs--;
		return $texte;
	}


	/**
	 * DEV : affiche $data (uniquement en environnement DEV)
	 * @param mixed $data
	 * @param string $titre = null
	 */
	protected function vardumpDev($data, $titre = null) {
		$texte = "";
		if($this->isDev()) {
			$texte .= ("<div style='border:1px dotted #666;padding:4px 8px;margin:8px 24px;'>");
			if($titre !== null && is_string($titre) && strlen($titre) > 0) {
				$texte .= ('<h3 style="margin-top:0px;padding-top:0px;border-bottom:1px dotted #999;margin-bottom:4px;">'.$titre.'</h3>');
			}
			$texte .= $this->getPreData($data);
			$texte .= ("</div>");
		}
		$this->DEVdata[self::NOM_DEV_DATA_SHOW][] = $texte;
	}

	protected function affAllDev() {
		// if($this->container->get('request')->attributes->get('_controller') !== null) {
			// if($this->container->get('security.context')->isGranted('ROLE_SUPER_ADMIN') && ($this->isDev())) {
			if($this->isDev()) {
				$devdd = $this->getDevData(self::NOM_DEV_DATA_SHOW);
				if($devdd !== null) {
					if(count($devdd) > 0) {
						echo("<h2>Données DEV : </h2>");
						foreach ($devdd as $key => $value) {
							echo($value);
						}
						echo("<br><br><br><br>");
					}
				}
			}
		// }
	}

	private function test($data) {
		echo('<pre>');
		var_dump($data);
		die('</pre>');
	}

}