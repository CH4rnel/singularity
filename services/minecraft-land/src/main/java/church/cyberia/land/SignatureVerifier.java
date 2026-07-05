package church.cyberia.land;

import java.math.BigInteger;
import java.nio.charset.StandardCharsets;
import org.web3j.crypto.Keys;
import org.web3j.crypto.Sign;
import org.web3j.utils.Numeric;

public final class SignatureVerifier {
    private SignatureVerifier() {}

    public static boolean verifyPersonalSign(String message, String signatureHex, String expectedAddress) {
        try {
            byte[] signature = Numeric.hexStringToByteArray(signatureHex);
            if (signature.length != 65) {
                return false;
            }
            byte[] r = new byte[32];
            byte[] s = new byte[32];
            System.arraycopy(signature, 0, r, 0, 32);
            System.arraycopy(signature, 32, s, 0, 32);
            byte v = signature[64];
            if (v < 27) {
                v += 27;
            }
            BigInteger publicKey = Sign.signedPrefixedMessageToKey(
                message.getBytes(StandardCharsets.UTF_8),
                new Sign.SignatureData(v, r, s)
            );
            String recovered = Numeric.prependHexPrefix(Keys.getAddress(publicKey));
            return recovered.equalsIgnoreCase(expectedAddress);
        } catch (RuntimeException | java.security.SignatureException ignored) {
            return false;
        }
    }
}
