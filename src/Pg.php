<?php
/*************************************************************************************
 *   MIT License                                                                     *
 *                                                                                   *
 *   Copyright (C) 2009-2019 by Nurlan Mukhanov <nurike@gmail.com>                   *
 *                                                                                   *
 *   Permission is hereby granted, free of charge, to any person obtaining a copy    *
 *   of this software and associated documentation files (the "Software"), to deal   *
 *   in the Software without restriction, including without limitation the rights    *
 *   to use, copy, modify, merge, publish, distribute, sublicense, and/or sell       *
 *   copies of the Software, and to permit persons to whom the Software is           *
 *   furnished to do so, subject to the following conditions:                        *
 *                                                                                   *
 *   The above copyright notice and this permission notice shall be included in all  *
 *   copies or substantial portions of the Software.                                 *
 *                                                                                   *
 *   THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR      *
 *   IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,        *
 *   FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE     *
 *   AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER          *
 *   LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,   *
 *   OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE   *
 *   SOFTWARE.                                                                       *
 ************************************************************************************/

namespace DBD;

use Falseclock\DBD\Common\DBDPHPException;
use Falseclock\DBD\Common\DBDPHPException as Exception;
use Falseclock\DBD\Entity\Column;
use Falseclock\DBD\Entity\Primitive;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;

/**
 * Class Pg
 *
 * @package DBD
 */
class Pg extends DBD
{
	const CAST_FORMAT_INSERT = "?::%s";
	const CAST_FORMAT_UPDATE = "%s = ?::%s";

	/**
	 * Setup connection to the resource
	 *
	 * @return Pg
	 * @throws Exception
	 */
	public function connect() {

		$dsn = "host={$this->Config->getDsn()} ";
		$dsn .= "dbname={$this->Config->getDatabase()} ";
		$dsn .= $this->Config->getUsername() ? "user={$this->Config->getUsername()} " : "";
		$dsn .= $this->Config->getPassword() ? "password={$this->Config->getPassword()} " : "";
		$dsn .= $this->Config->getPort() ? "port={$this->Config->getPort()} " : "";
		$dsn .= "application_name={$this->Config->getIdentity()} ";

		$this->Config->setDsn($dsn);

		if($this->Options->isOnDemand() === false) {
			$this->_connect();
		}

		return $this;
	}

	/**
	 * returns the number of tuples (instances/records/rows) affected by INSERT, UPDATE, and DELETE queries.
	 *
	 * @return int
	 */
	protected function _affectedRows() {
		return pg_affected_rows($this->result);
	}

	/**
	 * Sends BEGIN; command
	 *
	 * @return resource
	 */
	protected function _begin() {
		return $this->_query("BEGIN;");
	}

	/**
	 * Send's COMMIT; command
	 *
	 * @return resource
	 */
	protected function _commit() {
		return $this->_query("COMMIT;");
	}

	/**
	 * Compiles INSERT query
	 *
	 * @param string $table
	 * @param array  $params
	 * @param string $return
	 *
	 * @return string
	 */
	protected function _compileInsert($table, $params, $return = "") {
		return "INSERT INTO $table ({$params['COLUMNS']}) VALUES ({$params['VALUES']})" . ($return ? " RETURNING {$return}" : "");
	}

	/**
	 * Compiles UPDATE query
	 *
	 * @param string $table
	 * @param array  $params
	 * @param string $where
	 * @param string $return
	 *
	 * @return string
	 */
	protected function _compileUpdate($table, $params, $where, $return = "") {
		/** @noinspection SqlWithoutWhere */
		return "UPDATE $table SET {$params['COLUMNS']}" . ($where ? " WHERE $where" : "") . ($return ? " RETURNING {$return}" : "");
	}

	/**
	 * Do real connection. Can be invoked if OnDemand is set to TRUE
	 *
	 * @return void
	 * @throws Exception
	 */
	protected function _connect() {
		$this->resourceLink = pg_connect($this->Config->getDsn());

		if(!$this->resourceLink)
			throw new Exception("Can not connect to PostgreSQL server! ");
	}

	/**
	 * @param $data
	 * @param $type
	 *
	 * @return array|mixed
	 * @throws Exception
	 */
	protected function _convertBoolean(&$data, $type) {
		if($type == 'row') {
			if(isset($data) and is_array($data) and count($data) > 0) {
				for($i = 0; $i < pg_num_fields($this->result); $i++) {
					if(pg_field_type($this->result, $i) == 'bool') {
						$dataKey = pg_field_name($this->result, $i);
						if(array_keys($data) !== range(0, count($data) - 1)) {
							$key = $dataKey;
						}
						else {
							$key = $i;
						}
						if($data[$key] == 't') {
							$data[$key] = true;
						}
						else if($data[$key] == 'f') {
							$data[$key] = false;
						}
						else if($data[$key] == null) {
							$data[$key] = null;
						}
						else {
							throw new Exception("Unexpected boolean value");
						}
					}
				}
			}
		}

		return $data;
	}

	/**
	 * Convert integer, double and float values to corresponding PHP types. By default Postgres returns them as string
	 *
	 * @param $data
	 * @param $type
	 *
	 * @return mixed
	 * @throws Exception
	 */
	protected function _convertIntFloat(&$data, $type) {
		// TODO: in case of fetchrowset do not get each time and use static variable
		if($data && pg_num_fields($this->result) != count($data)) {

			$names = [];
			for($i = 0; $i < pg_num_fields($this->result); $i++) {
				$names[pg_field_name($this->result, $i)]++;
			}
			$names = array_filter($names,
				function($v) {
					return $v > 1;
				}
			);

			$duplications = "";

			foreach($names as $key => $value) {
				$duplications .= "[{$key}] => {$value}, ";
			}

			throw new Exception("Statement result has " . pg_num_fields($this->result) . " columns while fetched row only " . count($data) . ". 
				Fetching it associative reduces number of columns. 
				Rename column with `AS` inside statement or fetch as indexed array.\n\n
				Duplicating columns are: {$duplications}\n"
			);
		}

		$types = [];

		$map = [
			'int'         => 'integer',
			'int2'        => 'integer',
			'int4'        => 'integer',
			'int8'        => 'integer',
			'serial2'     => 'integer',
			'serial4'     => 'integer',
			'serial8'     => 'integer',
			'smallint'    => 'integer',
			'bigint'      => 'integer',
			'serial'      => 'integer',
			'smallserial' => 'integer',
			'bigserial'   => 'integer',
			//'numeric'   => 'float',
			//'decimal'     => 'float',
			'real'        => 'float',
			'float'       => 'float',
			'float4'      => 'float',
			'float8'      => 'float',
		];

		if($type == 'row') {
			if($data) {
				// count how many fields we have and get their types
				for($i = 0; $i < pg_num_fields($this->result); $i++) {
					$types[] = pg_field_type($this->result, $i);
				}

				// Identify on which column we are
				$i = 0;
				//        row    idx      value
				foreach($data as $key => $value) {
					// if type of current column exist in map array
					if(array_key_exists($types[$i], $map)) {
						// using data key, cause can be
						//printf("Type: %s\n",$types[$i]);
						if(isset($value)) {
							$data[$key] = ($map[$types[$i]] == 'integer' ? intval($value) : floatval($value));
						}
					}
					$i++;
				}
			}
		}

		return $data;
	}

	/**
	 * Closes the non-persistent connection to a PostgreSQL database associated with the given connection resource
	 *
	 * @return bool
	 */
	protected function _disconnect() {
		return pg_close($this->resourceLink);
	}

	/**
	 * Returns the last error message for a given connection.
	 *
	 * @return string
	 */
	protected function _errorMessage() {
		if($this->resourceLink)
			return pg_last_error($this->resourceLink);
		else
			return pg_last_error();
	}

	/**
	 * Escapes a string for querying the database.
	 *
	 * @param $value
	 *
	 * @return string
	 */
	protected function _escape($value) {
		if(!isset($value) or $value === null) {
			return "NULL";
		}
		/*		if(is_numeric($value)) {
					return $value;
				}*/
		if(is_bool($value)) {
			return ($value) ? "TRUE" : "FALSE";
		}
		$str = pg_escape_string($value);

		return "'$str'";
	}

	/**
	 * @param $uniqueName
	 * @param $arguments
	 *
	 * @return mixed
	 * @see MSSQL::_execute
	 * @see MySQL::_execute
	 * @see OData::_execute
	 * @see Pg::_execute
	 */
	protected function _execute($uniqueName, $arguments) {
		return @pg_execute($this->resourceLink, $uniqueName, $arguments);
	}

	/**
	 * Returns an array that corresponds to the fetched row (record).
	 *
	 * @return array
	 */
	protected function _fetchArray() {
		return pg_fetch_array($this->result, 0, PGSQL_NUM);
	}

	/**
	 * Returns an associative array that corresponds to the fetched row (records).
	 *
	 * @return array
	 */
	protected function _fetchAssoc() {
		return pg_fetch_assoc($this->result);
	}

	/**
	 * Will return the number of rows in a PostgreSQL result resource.
	 *
	 * @return int
	 */
	protected function _numRows() {
		return pg_affected_rows($this->result);
	}

	/**
	 * @param $uniqueName
	 *
	 * @param $statement
	 *
	 * @return mixed
	 * @see MSSQL::_prepare
	 * @see MySQL::_prepare
	 * @see OData::_prepare
	 * @see Pg::_prepare
	 */
	protected function _prepare($uniqueName, $statement) {
		return @pg_prepare($this->resourceLink, $uniqueName, $statement);
	}

	/**
	 * Executes the query on the specified database connection.
	 *
	 * @param $statement
	 *
	 * @return resource|bool
	 */
	protected function _query($statement) {
		return @pg_query($this->resourceLink, $statement);
	}

	/**
	 * Sends ROLLBACK; command
	 *
	 * @return resource
	 */
	protected function _rollback() {
		return $this->_query("ROLLBACK;");
	}

	/**
	 * @param string $table
	 * @param string $schema
	 *
	 * @return Column[]
	 * @throws Exception
	 * @throws InvalidArgumentException
	 * @throws ReflectionException
	 * @see MSSQL::_tableStructure
	 * @see MySQL::_tableStructure
	 * @see OData::_tableStructure
	 * @see tableStructure
	 * @see Pg::_tableStructure
	 */
	protected function _tableStructure($table, $schema = null) {
		// Postgres uses dot symbol to separate schema and table
		if(!isset($schema)) {
			//Get the first occurrence of a character.
			$dotPosition = strpos($table, '.');
			if($dotPosition === false) {
				throw new Exception("No schema provided");
			}
			$initialTable = $table;
			$schema = substr($initialTable, 0, $dotPosition);
			$table = substr($initialTable, $dotPosition + 1);
		}
		$sth = $this->prepare("
			SELECT cols.column_name,
				   CASE WHEN is_nullable = 'NO' THEN false WHEN is_nullable = 'YES' THEN true ELSE null END AS is_nullable,
				   data_type,
				   udt_name,
				   character_maximum_length,
				   numeric_precision,
				   numeric_scale,
				   datetime_precision,
				   column_default,
				   (
					   SELECT pg_catalog.col_description(c.oid, cols.ordinal_position::int)
					   FROM pg_catalog.pg_class c
					   WHERE c.oid = (SELECT cols.table_name::regclass::oid)
						 AND c.relname = cols.table_name
				   ) AS column_comment
			
			FROM information_schema.columns cols
			WHERE cols.table_name = ?
			  AND cols.table_schema = ?
            ORDER BY ordinal_position
		"
		);
		$sth->execute($table, $schema);

		if($sth->rows()) {
			$columns = [];
			while($row = $sth->fetchRow()) {
				$column = new Column();
				$column->name = $row['column_name'];
				$column->nullable = $row['is_nullable'];
				if(isset($row['character_maximum_length']))
					$column->maxLength = $row['character_maximum_length'];

				if(isset($row['numeric_precision']))
					$column->precision = $row['numeric_precision'];

				if(isset($row['numeric_scale']))
					$column->scale = $row['numeric_scale'];

				if(isset($row['datetime_precision']))
					$column->precision = $row['datetime_precision'];

				if(isset($row['column_default']))
					$column->defaultValue = $row['column_default'];

				if(isset($row['column_comment']))
					$column->annotation = $row['column_comment'];

				$column->type = $this->getPrivitive($row['udt_name']);

				if(in_array($column->type->getValue(), [ Primitive::Int16, Primitive::Int32(), Primitive::Int64 ])) {
					$column->scale = null;
					$column->precision = null;
				}

				$columns[] = $column;
			}

			return $columns;
		}

		return [];
	}

	/**
	 * @param string $type
	 *
	 * @return Primitive
	 * @throws Exception
	 */
	private function getPrivitive(string $type) {
		switch(strtolower(trim($type))) {

			case 'bytea':
				return Primitive::Binary();
				break;

			case 'boolean':
			case 'bool':
				return Primitive::Boolean();
				break;

			case 'date':
			case 'timestamp':
				return Primitive::Date();
				break;

			case 'timestamptz':
				return Primitive::DateTimeOffset();
				break;

			case 'numeric':
			case 'decimal':
				return Primitive::Decimal();
				break;

			case 'float8':
				return Primitive::Double();
				break;

			case 'interval':
				return Primitive::Duration();
				break;

			case 'uuid':
				return Primitive::Guid();
				break;

			case 'int2':
			case 'smallint':
			case 'smallserial':
			case 'serial2':
				return Primitive::Int16();
				break;

			case 'int':
			case 'int4':
			case 'integer':
			case 'serial4':
			case 'serial':
				return Primitive::Int32();
				break;

			case 'int8':
			case 'bigint':
			case 'bigserial':
			case 'serial8':
				return Primitive::Int64();
				break;

			case 'float4':
			case 'real':
				return Primitive::Single();
				break;

			case 'varchar':
			case 'text':
			case 'cidr':
			case 'inet':
			case 'json':
			case 'jsonb':
			case 'macaddr':
			case 'macaddr8':
			case 'char':
			case 'tsquery':
			case 'tsvector':
			case 'xml':
				return Primitive::String();
				break;
		}

		throw new DBDPHPException("Not described type found: {$type}");
	}
}