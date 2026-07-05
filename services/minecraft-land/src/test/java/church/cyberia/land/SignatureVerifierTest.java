package church.cyberia.land;

import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.nio.charset.StandardCharsets;
import org.junit.jupiter.api.Test;
import org.web3j.crypto.Credentials;
import org.web3j.crypto.Sign;
import org.web3j.utils.Numeric;

class SignatureVerifierTest {
    @Test
    void acceptsOnlyTheWalletThatSignedTheExactChallenge() {
        Credentials signer = Credentials.create("11".repeat(32));
        String message = "Cyberia Minecraft wallet link\nNonce: test";
        Sign.SignatureData data = Sign.signPrefixedMessage(
            message.getBytes(StandardCharsets.UTF_8),
            signer.getEcKeyPair()
        );
        byte[] signature = new byte[65];
        System.arraycopy(data.getR(), 0, signature, 0, 32);
        System.arraycopy(data.getS(), 0, signature, 32, 32);
        signature[64] = data.getV()[0];

        assertTrue(SignatureVerifier.verifyPersonalSign(message, Numeric.toHexString(signature), signer.getAddress()));
        assertFalse(SignatureVerifier.verifyPersonalSign(message + "!", Numeric.toHexString(signature), signer.getAddress()));
        assertFalse(SignatureVerifier.verifyPersonalSign(message, "0x1234", signer.getAddress()));
    }
}
