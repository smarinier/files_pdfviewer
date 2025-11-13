/**
 * Functions for PDF decryption
 */

/**
 * decode base64 -> Uint8Array
 * @param {string} b64 - base64 string
 * @return {Uint8Array}
 */
export const base64ToUint8Array = function(b64) {
	b64 = String(b64).replace(/-/g, '+').replace(/_/g, '/')
	while (b64.length % 4) b64 += '='
	const bin = atob(b64) // peut lancer si la chaîne n'est pas valide
	const len = bin.length
	const bytes = new Uint8Array(len)
	for (let i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i)
	return bytes
}

// decode base64 -> ArrayBuffer
export const base64ToArrayBuffer = (b64) => base64ToUint8Array(b64).buffer

/**
 * Create imported key
 * @param {string} b64HashKey - base64 encoded sha256 hashed key
 * @return {CryptoKey} imported CryptoKey
 */
export const importKey = async function(b64HashKey) {
	const rawKey = base64ToArrayBuffer(b64HashKey)
	return crypto.subtle.importKey(
		'raw',
		rawKey,
		{ name: 'AES-CBC' },
		false,
		['decrypt'],
	)
}

/**
 * Decrypt a chunk of data
 * @param {Uint8Array} buffer - encrypted data with IV prepended
 * @param {CryptoKey} key - imported CryptoKey
 * @return {Uint8Array} decrypted data
 */
export const decryptChunk = async function(buffer, key) {
	const iv = buffer.slice(0, 16)
	const data = buffer.slice(16)
	const decrypted = await crypto.subtle.decrypt({ name: 'AES-CBC', iv }, key, data)
	return new Uint8Array(decrypted)
}
