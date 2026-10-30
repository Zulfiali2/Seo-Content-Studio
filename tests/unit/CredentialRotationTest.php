<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\Security\CredentialStore;
use AiSeoContentStudio\Security\Encryption;

require_once dirname( __DIR__ ) . '/fakes.php';

class CredentialRotationTest extends TestCase {

	public function testValueEncryptedWithPreviousKeyIsReadAndReEncrypted(): void {
		$GLOBALS['fake_options'] = array();
		$old = new Encryption( str_repeat( 'o', 32 ) );
		$new = new Encryption( str_repeat( 'n', 32 ) );
		( new CredentialStore( $old ) )->set( 'openai', 'api_key', 'sk-test-1234567890' );
		$stored_before = $GLOBALS['fake_options'][ CredentialStore::OPTION ]['openai']['api_key'];

		$this->assertNull( ( new CredentialStore( $new ) )->get( 'openai', 'api_key' ), 'new key alone cannot read it' );

		$rotating = new CredentialStore( $new, $old );
		$this->assertSame( 'sk-test-1234567890', $rotating->get( 'openai', 'api_key' ) );
		$stored_after = $GLOBALS['fake_options'][ CredentialStore::OPTION ]['openai']['api_key'];
		$this->assertFalse( $stored_before === $stored_after, 're-encrypted' );
		$this->assertSame( 'sk-test-1234567890', ( new CredentialStore( $new ) )->get( 'openai', 'api_key' ), 'readable with the new key only' );
		$this->assertNull( $old->decrypt( $stored_after ), 'old key no longer opens it' );
	}

	public function testWrongPreviousKeyLeavesValueUntouched(): void {
		$GLOBALS['fake_options'] = array();
		( new CredentialStore( new Encryption( str_repeat( 'a', 32 ) ) ) )->set( 'x', 'k', 'secret-value' );
		$before = $GLOBALS['fake_options'];
		$store  = new CredentialStore( new Encryption( str_repeat( 'b', 32 ) ), new Encryption( str_repeat( 'c', 32 ) ) );
		$this->assertNull( $store->get( 'x', 'k' ) );
		$this->assertSame( $before, $GLOBALS['fake_options'] );
	}
}
