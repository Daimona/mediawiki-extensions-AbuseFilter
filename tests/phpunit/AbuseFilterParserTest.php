<?php
/**
 * Tests for the AbuseFilter parser
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 *
 * @license GPL-2.0-or-later
 * @author Marius Hoch < hoo@online.de >
 */

/**
 * @group Test
 * @group AbuseFilter
 *
 * @covers AbuseFilterCachingParser
 * @covers AbuseFilterParser
 * @covers AbuseFilterTokenizer
 */
class AbuseFilterParserTest extends MediaWikiTestCase {
	/**
	 * @return AbuseFilterParser
	 */
	public static function getParser() {
		static $parser = null;
		if ( !$parser ) {
			$parser = new AbuseFilterParser();
		} else {
			$parser->resetState();
		}
		return $parser;
	}

	/**
	 * @return AbuseFilterParser[]
	 */
	public static function getParsers() {
		static $parsers = null;
		if ( !$parsers ) {
			$parsers = [
				new AbuseFilterParser(),
				new AbuseFilterCachingParser()
			];
		}
		return $parsers;
	}

	/**
	 * @dataProvider readTests
	 */
	public function testParser( $testName, $rule, $expected ) {
		foreach ( self::getParsers() as $parser ) {
			$actual = $parser->parse( $rule );
			$this->assertEquals( $expected, $actual, 'Running parser test ' . $testName );
		}
	}

	/**
	 * @return array
	 */
	public function readTests() {
		$tests = [];
		$testPath = __DIR__ . "/../parserTests";
		$testFiles = glob( $testPath . "/*.t" );

		foreach ( $testFiles as $testFile ) {
			$testName = substr( $testFile, 0, -2 );

			$resultFile = $testName . '.r';
			$rule = trim( file_get_contents( $testFile ) );
			$result = trim( file_get_contents( $resultFile ) ) == 'MATCH';

			$tests[] = [
				basename( $testName ),
				$rule,
				$result
			];
		}

		return $tests;
	}

	/**
	 * Ensure that AbuseFilterTokenizer::OPERATOR_RE matches the contents
	 * and order of AbuseFilterTokenizer::$operators.
	 */
	public function testOperatorRe() {
		$operatorRe = '/(' . implode( '|', array_map( function ( $op ) {
			return preg_quote( $op, '/' );
		}, AbuseFilterTokenizer::$operators ) ) . ')/A';
		$this->assertEquals( $operatorRe, AbuseFilterTokenizer::OPERATOR_RE );
	}

	/**
	 * Ensure that AbuseFilterTokenizer::RADIX_RE matches the contents
	 * and order of AbuseFilterTokenizer::$bases.
	 */
	public function testRadixRe() {
		$baseClass = implode( '', array_keys( AbuseFilterTokenizer::$bases ) );
		$radixRe = "/([0-9A-Fa-f]+(?:\.\d*)?|\.\d+)([$baseClass])?/Au";
		$this->assertEquals( $radixRe, AbuseFilterTokenizer::RADIX_RE );
	}

	/**
	 * Ensure the number of conditions counted for given expressions is right.
	 *
	 * @dataProvider condCountCases
	 */
	public function testCondCount( $rule, $expected ) {
		$parser = self::getParser();
		// Set some variables for convenience writing test cases
		$parser->setVars( array_combine( range( 'a', 'f' ), range( 'a', 'f' ) ) );
		$countBefore = AbuseFilter::$condCount;
		$parser->parse( $rule );
		$countAfter = AbuseFilter::$condCount;
		$actual = $countAfter - $countBefore;
		$this->assertEquals( $expected, $actual, 'Condition count for ' . $rule );
	}

	/**
	 * Data provider for testCondCount method.
	 * @return array
	 */
	public function condCountCases() {
		return [
			[ '(((a == b)))', 1 ],
			[ 'contains_any(a, b, c)', 1 ],
			[ 'a == b == c', 2 ],
			[ 'a in b + c in d + e in f', 3 ],
			[ 'true', 0 ],
			[ 'a == a | c == d', 1 ],
			[ 'a == b & c == d', 1 ],
		];
	}

	/**
	 * get_matches should throw an exception with an invalid number of arguments.
	 * @expectedException AFPUserVisibleException
	 * @covers AbuseFilterParser::funcGetMatches
	 */
	public function testGetMatchesInvalidArgs() {
		$parser = self::getParser();
		$parser->parse( "get_matches('')" );
	}

	/**
	 * get_matches should throw an exception when given an invalid regular expression.
	 * @expectedException AFPUserVisibleException
	 * @covers AbuseFilterParser::funcGetMatches
	 */
	public function testGetMatchesInvalidRegex() {
		$parser = self::getParser();
		$parser->parse( "get_matches('this (should fail')" );
	}

	/**
	 * Ensure get_matches function captures returns expected output.
	 * @param string $needle Regex to pass to get_matches.
	 * @param string $haystack String to run regex against.
	 * @param string[] $expected The expected values of the matched groups.
	 * @covers AbuseFilterParser::funcGetMatches
	 * @dataProvider getMatchesCases
	 */
	public function testGetMatches( $needle, $haystack, $expected ) {
		$parser = self::getParser();
		$afpData = $parser->intEval( "get_matches('$needle', '$haystack')" )->data;

		// Extract matches from AFPData.
		$matches = array_map( function ( $afpDatum ) {
			return $afpDatum->data;
		}, $afpData );

		$this->assertEquals( $expected, $matches );
	}

	/**
	 * Data provider for get_matches method.
	 * @return array
	 */
	public function getMatchesCases() {
		return [
			[
				'You say (.*) \(and I say (.*)\)\.',
				'You say hello (and I say goodbye).',
				[
					'You say hello (and I say goodbye).',
					'hello',
					'goodbye',
				],
			],
			[
				'I(?: am)? the ((walrus|egg man).*)\!',
				'I am the egg man, I am the walrus !',
				[
					'I am the egg man, I am the walrus !',
					'egg man, I am the walrus ',
					'egg man',
				],
			],
			[
				'this (does) not match',
				'foo bar',
				[
					false,
					false,
				],
			],
		];
	}

	/**
	 * Base method for testing exceptions
	 *
	 * @param string $excep Identifier of the exception (e.g. 'unexpectedtoken')
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 */
	private function exceptionTest( $excep, $expr, $caller ) {
		$parser = self::getParser();
		try {
			$parser->parse( $expr );
		} catch ( AFPUserVisibleException $e ) {
			$this->assertEquals(
				$excep,
				$e->mExceptionID,
				"Exception $excep not thrown in AbuseFilterParser::$caller"
			);
			return;
		}

		$this->fail( "Exception $excep not thrown in AbuseFilterParser::$caller" );
	}

	/**
	 * Test the 'expectednotfound' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterParser::doLevelSet
	 * @covers AbuseFilterParser::doLevelConditions
	 * @covers AbuseFilterParser::doLevelBraces
	 * @covers AbuseFilterParser::doLevelFunction
	 * @covers AbuseFilterParser::doLevelAtom
	 * @covers AbuseFilterParser::skipOverBraces
	 * @covers AbuseFilterParser::doLevelArrayElements
	 * @dataProvider expectedNotFound
	 */
	public function testExpectedNotFoundException( $expr, $caller ) {
		$this->exceptionTest( 'expectednotfound', $expr, $caller );
	}

	/**
	 * Data provider for testExpectedNotFoundException.
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function expectedNotFound() {
		return [
			[ 'a:= [1,2,3]; a[1 = 4', 'doLevelSet' ],
			[ "if 1 = 1 'foo'", 'doLevelConditions' ],
			[ "if 1 = 1 then 'foo'", 'doLevelConditions' ],
			[ "if 1 = 1 then 'foo' else 'bar'", 'doLevelConditions' ],
			[ "a := 1 = 1 ? 'foo'", 'doLevelConditions' ],
			[ '(1 = 1', 'doLevelBraces' ],
			[ 'lcase = 3', 'doLevelFunction' ],
			[ 'lcase( 3 = 1', 'doLevelFunction' ],
			[ 'a := [1,2', 'doLevelAtom' ],
			[ '1 = 1 | (', 'skipOverBraces' ],
			[ 'a := [1,2,3]; 3 = a[5', 'doLevelArrayElements' ],
		];
	}

	/**
	 * Test the 'unexpectedatend' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterParser::doLevelEntry
	 * @dataProvider unexpectedAtEnd
	 */
	public function testUnexpectedAtEndException( $expr, $caller ) {
		$this->exceptionTest( 'unexpectedatend', $expr, $caller );
	}

	/**
	 * Data provider for testUnexpectedAtEndException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function unexpectedAtEnd() {
		return [
			[ "'a' = 1 )", 'doLevelEntry' ],
		];
	}

	/**
	 * Test the 'unrecognisedvar' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterParser::doLevelSet
	 * @covers AbuseFilterParser::getVarValue
	 * @dataProvider unrecognisedVar
	 */
	public function testUnrecognisedVarException( $expr, $caller ) {
		$this->exceptionTest( 'unrecognisedvar', $expr, $caller );
	}

	/**
	 * Data provider for testUnrecognisedVarException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function unrecognisedVar() {
		return [
			[ 'a[1] := 5', 'doLevelSet' ],
			[ 'a = 5', 'getVarValue' ],
		];
	}

	/**
	 * Test the 'notarray' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterParser::doLevelSet
	 * @covers AbuseFilterParser::doLevelArrayElements
	 * @dataProvider notArray
	 */
	public function testNotArrayException( $expr, $caller ) {
		$this->exceptionTest( 'notarray', $expr, $caller );
	}

	/**
	 * Data provider for testNotArrayException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function notArray() {
		return [
			[ 'a := 5; a[1] = 5', 'doLevelSet' ],
			[ 'a := 1; 3 = a[5]', 'doLevelArrayElements' ],
		];
	}

	/**
	 * Test the 'outofbounds' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterParser::doLevelSet
	 * @covers AbuseFilterParser::doLevelArrayElements
	 * @dataProvider outOfBounds
	 */
	public function testOutOfBoundsException( $expr, $caller ) {
		$this->exceptionTest( 'outofbounds', $expr, $caller );
	}

	/**
	 * Data provider for testOutOfBoundsException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function outOfBounds() {
		return [
			[ 'a := [2]; a[5] = 9', 'doLevelSet' ],
			[ 'a := [1,2,3]; 3 = a[5]', 'doLevelArrayElements' ],
		];
	}

	/**
	 * Test the 'unrecognisedkeyword' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterParser::doLevelAtom
	 * @dataProvider unrecognisedKeyword
	 */
	public function testUnrecognisedKeywordException( $expr, $caller ) {
		$this->exceptionTest( 'unrecognisedkeyword', $expr, $caller );
	}

	/**
	 * Data provider for testUnrecognisedKeywordException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function unrecognisedKeyword() {
		return [
			[ '5 = rlike', 'doLevelAtom' ],
		];
	}

	/**
	 * Test the 'unexpectedtoken' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterParser::doLevelAtom
	 * @dataProvider unexpectedToken
	 */
	public function testUnexpectedTokenException( $expr, $caller ) {
		$this->exceptionTest( 'unexpectedtoken', $expr, $caller );
	}

	/**
	 * Data provider for testUnexpectedTokenException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function unexpectedToken() {
		return [
			[ '1 =? 1', 'doLevelAtom' ],
		];
	}

	/**
	 * Test the 'disabledvar' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterParser::getVarValue
	 * @dataProvider disabledVar
	 */
	public function testDisabledVarException( $expr, $caller ) {
		$this->exceptionTest( 'disabledvar', $expr, $caller );
	}

	/**
	 * Data provider for testDisabledVarException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function disabledVar() {
		return [
			[ 'old_text = 1', 'getVarValue' ],
		];
	}

	/**
	 * Test the 'overridebuiltin' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterParser::setUserVariable
	 * @dataProvider overrideBuiltin
	 */
	public function testOverrideBuiltinException( $expr, $caller ) {
		$this->exceptionTest( 'overridebuiltin', $expr, $caller );
	}

	/**
	 * Data provider for testOverrideBuiltinException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function overrideBuiltin() {
		return [
			[ 'added_lines := 1', 'setUserVariable' ],
		];
	}

	/**
	 * Test the 'regexfailure' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterParser::funcRCount
	 * @dataProvider regexFailure
	 */
	public function testRegexFailureException( $expr, $caller ) {
		$this->exceptionTest( 'regexfailure', $expr, $caller );
	}

	/**
	 * Data provider for testRegexFailureException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function regexFailure() {
		return [
			[ "rcount('(','a')", 'funcRCount' ],
		];
	}

	/**
	 * Test the 'invalidiprange' exception
	 *
	 * @param string $expr The expression to test
	 * @param string $caller The function where the exception is thrown
	 * @covers AbuseFilterParser::funcIPInRange
	 * @dataProvider invalidIPRange
	 */
	public function testInvalidIPRangeException( $expr, $caller ) {
		$this->exceptionTest( 'invalidiprange', $expr, $caller );
	}

	/**
	 * Data provider for testInvalidIPRangeException
	 * The second parameter is the function where the exception is raised.
	 * One expression for each throw.
	 *
	 * @return array
	 */
	public function invalidIPRange() {
		return [
			[ "ip_in_range('0.0.0.0', 'lol')", 'funcIPInRange' ],
		];
	}
}
