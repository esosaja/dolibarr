<?php
/* Copyright (C) 2015   Jean-François Ferry     <jfefe@aternatik.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

use Luracast\Restler\RestException;

//require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';

/**
 * API class for contacts
 *
 * @access protected 
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Contacts extends DolibarrApi
{
	/**
	 *
	 * @var array   $FIELDS     Mandatory fields, checked when create and update object 
	 */
	static $FIELDS = array(
		'lastname'
	);

	/**
	 * @var Contact $contact {@type Contact}
	 */
	public $contact;

	/**
	 * Constructor
	 */
	function __construct() {
		global $db, $conf;
		$this->db = $db;
		$this->contact = new Contact($this->db);
	}

	/**
	 * Get properties of a contact object
	 *
	 * Return an array with contact informations
	 *
	 * @param 	int 	$id ID of contact
	 * @return 	array|mixed data without useless information
	 * 
	 * @throws 	RestException
	 */
	function get($id) {
		if (!DolibarrApiAccess::$user->rights->societe->contact->lire)
		{
			throw new RestException(401);
		}

		$result = $this->contact->fetch($id);
		if (!$result)
		{
			throw new RestException(404, 'Contact not found');
		}

		if (!DolibarrApi::_checkAccessToResource('contact', $this->contact->id, 'socpeople&societe'))
		{
			throw new RestException(401, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
		}

		return $this->_cleanObjectDatas($this->contact);
	}

	/**
	 * List contacts
	 * 
	 * Get a list of contacts
	 * 
	 * @param string	$sortfield	Sort field
	 * @param string	$sortorder	Sort order
	 * @param int		$limit		Limit for list
	 * @param int		$page		Page number
	 * @param int		$socid		ID of thirdparty to filter list
     * @param string    $sqlfilters Other criteria to filter answers separated by a comma. Syntax example "(t.ref:like:'SO-%') and (t.date_creation:<:'20160101')"
	 * @return array                Array of contact objects
     * 
	 * @throws RestException
	 */
	function index($sortfield = "t.rowid", $sortorder = 'ASC', $limit = 0, $page = 0, $socid = 0, $sqlfilters = '') {
		global $db, $conf;

		$obj_ret = array();

		if (!$socid)
		{
			$socid = DolibarrApiAccess::$user->societe_id ? DolibarrApiAccess::$user->societe_id : '';
		}

		// If the internal user must only see his customers, force searching by him
		if (!DolibarrApiAccess::$user->rights->societe->client->voir && !$socid)
			$search_sale = DolibarrApiAccess::$user->id;

		$sql = "SELECT t.rowid";
		$sql.= " FROM " . MAIN_DB_PREFIX . "socpeople as t";
		if ((!DolibarrApiAccess::$user->rights->societe->client->voir && !$socid) || $search_sale > 0) {
			// We need this table joined to the select in order to filter by sale
			$sql.= ", " . MAIN_DB_PREFIX . "societe_commerciaux as sc"; 
		}
		$sql.= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON t.fk_soc = s.rowid";
		$sql.= ' WHERE t.entity IN (' . getEntity('socpeople', 1) . ')';
		if ($socid) $sql.= " AND t.fk_soc = " . $socid;

		if ((!DolibarrApiAccess::$user->rights->societe->client->voir && !$socid) || $search_sale > 0)
			$sql.= " AND t.fk_soc = sc.fk_soc";
		if ($search_sale > 0)
			$sql.= " AND s.rowid = sc.fk_soc";  // Join for the needed table to filter by sale
		// Insert sale filter
		if ($search_sale > 0)
		{
			$sql .= " AND sc.fk_user = " . $search_sale;
		}
	    // Add sql filters
        if ($sqlfilters) 
        {
            if (! DolibarrApi::_checkFilters($sqlfilters))
            {
                throw new RestException(503, 'Error when validating parameter sqlfilters '.$sqlfilters);
            }
	        $regexstring='\(([^:\'\(\)]+:[^:\'\(\)]+:[^:\(\)]+)\)';
            $sql.=" AND (".preg_replace_callback('/'.$regexstring.'/', 'DolibarrApi::_forge_criteria_callback', $sqlfilters).")";
        }
		
		$sql.= $db->order($sortfield, $sortorder);

		if ($limit)
		{
			if ($page < 0)
			{
				$page = 0;
			}
			$offset = $limit * $page;

			$sql.= $db->plimit($limit + 1, $offset);
		}
		$result = $db->query($sql);
		if ($result)
		{
			$num = $db->num_rows($result);
			while ($i < min($num, ($limit <= 0 ? $num : $limit)))
			{
				$obj = $db->fetch_object($result);
				$contact_static = new Contact($db);
				if ($contact_static->fetch($obj->rowid))
				{
					$obj_ret[] = parent::_cleanObjectDatas($contact_static);
				}
				$i++;
			}
		} 
		else {
			throw new RestException(503, 'Error when retreive contacts : ' . $sql);
		}
		if (!count($obj_ret))
		{
			throw new RestException(404, 'Contacts not found');
		}
		return $obj_ret;
	}

	/**
	 * Create contact object
	 *
	 * @param   array   $request_data   Request datas
	 * @return  int     ID of contact
	 */
	function post($request_data = NULL) {
		if (!DolibarrApiAccess::$user->rights->societe->contact->creer)
		{
			throw new RestException(401);
		}
		// Check mandatory fields
		$result = $this->_validate($request_data);

		foreach ($request_data as $field => $value)
		{
			$this->contact->$field = $value;
		}
		return $this->contact->create(DolibarrApiAccess::$user);
	}

	/**
	 * Update contact
	 *
	 * @param int   $id             Id of contact to update
	 * @param array $request_data   Datas   
	 * @return int 
	 */
	function put($id, $request_data = NULL) {
		if (!DolibarrApiAccess::$user->rights->societe->contact->creer)
		{
			throw new RestException(401);
		}

		$result = $this->contact->fetch($id);
		if (!$result)
		{
			throw new RestException(404, 'Contact not found');
		}

		if (!DolibarrApi::_checkAccessToResource('contact', $this->contact->id, 'socpeople&societe'))
		{
			throw new RestException(401, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
		}

		foreach ($request_data as $field => $value)
		{
			$this->contact->$field = $value;
		}

		if ($this->contact->update($id, DolibarrApiAccess::$user, 1, '', '', 'update'))
			return $this->get($id);

		return false;
	}

	/**
	 * Delete contact
	 *
	 * @param   int     $id Contact ID
	 * @return  integer
	 */
	function delete($id) {
		if (!DolibarrApiAccess::$user->rights->societe->contact->supprimer)
		{
			throw new RestException(401);
		}
		$result = $this->contact->fetch($id);
		if (!$result)
		{
			throw new RestException(404, 'Contact not found');
		}

		if (!DolibarrApi::_checkAccessToResource('contact', $this->contact->id, 'socpeople&societe'))
		{
			throw new RestException(401, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
		}

		return $this->contact->delete($id);
	}

	/**
	 * Create useraccount object from contact
	 *
	 * @param   int   $id   Id of contact
	 * @param   array   $request_data   Request datas
	 * @return  int     ID of user
	 *
	 * @url	POST {id}/createUser
	 */
	function createUser($id, $request_data = NULL) {
	    //if (!DolibarrApiAccess::$user->rights->user->user->creer) {
	    //throw new RestException(401);
	    //}
	
	    if (!isset($request_data["login"]))
	    				throw new RestException(400, "login field missing");
	    if (!isset($request_data["password"]))
	    				throw new RestException(400, "password field missing");
	    if (!DolibarrApiAccess::$user->rights->societe->contact->lire) {
	        throw new RestException(401);
	    }
	    $contact = new Contact($this->db);
	    $contact->fetch($id);
	    if ($contact->id <= 0) {
	        throw new RestException(404, 'Contact not found');
	    }
	
	    if (!DolibarrApi::_checkAccessToResource('contact', $contact->id, 'socpeople&societe')) {
	        throw new RestException(401, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
	    }
	    // Check mandatory fields
	    $login = $request_data["login"];
	    $password = $request_data["password"];
	    $useraccount = new User($this->db);
	    $result = $useraccount->create_from_contact($contact,$login,$password);
	    if ($result <= 0) {
	        throw new RestException(500, "User not created");
	    }
	    // password parameter not used in create_from_contact
	    $useraccount->setPassword($useraccount,$password);
	
	    return $result;
	}
	
    /**
     * Get categories for a contact
     *
     * @param int		$id         ID of contact
     * @param string	$sortfield	Sort field
     * @param string	$sortorder	Sort order
     * @param int		$limit		Limit for list
     * @param int		$page		Page number
     *
     * @return mixed
     *
     * @url GET {id}/categories
     */
    function getCategories($id, $sortfield = "s.rowid", $sortorder = 'ASC', $limit = 0, $page = 0) {
        $categories = new Categories();
        return $categories->getListForItem('contact', $sortfield, $sortorder, $limit, $page, $id);
    }

	/**
	 * Validate fields before create or update object
     * 
	 * @param   array|null     $data   Data to validate
	 * @return  array
	 * @throws RestException
	 */
	function _validate($data) {
		$contact = array();
		foreach (Contacts::$FIELDS as $field)
		{
			if (!isset($data[$field]))
				throw new RestException(400, "$field field missing");
			$contact[$field] = $data[$field];
		}
		return $contact;
	}
}
