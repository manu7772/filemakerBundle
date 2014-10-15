<?php
// filemakerBundle/services/filemakerservice.php

namespace filemakerBundle\services;

use Symfony\Component\DependencyInjection\ContainerInterface;

class filemakerservice {

	protected $data = array();
	protected $container;
	protected $APIfm;		// objet FileMaker
	protected $FMfind;		//

	protected $dbname = "GEODIAG_REF_WEB";
	protected $dbuser = "Tech";
	protected $dbpass = "geotech";

	public function __construct(ContainerInterface $container) {
		$this->container = $container;
		require_once(__DIR__.'/../../../../../../../../Web Publishing/publishing-engine/php/mavericks/lib/php/FileMaker.php');
		// Create a new connection to FMPHP_Sample database.
		// Location of FileMaker Server is assumed to be on the same machine,
		//  thus we assume hostspec is api default of 'http://localhost' as specified
		//  in filemaker-api.php.
		// If FMSA web server is on another machine, specify 'hostspec' as follows:
		//   $fm = new FileMaker('FMPHP_Sample', 'http://10.0.0.1');
		$this->APIfm = new \FileMaker($this->dbname);
		$this->APIfm->setProperty('username',$this->dbuser);
		$this->APIfm->setProperty('password',$this->dbpass);
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
	 * Renvoie la liste des lieux
	 * @return array
	 */
	public function getLieux() {
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
	}


}