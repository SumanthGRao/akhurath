import bcrypt from 'bcryptjs';

export function authenticateUser(username, password, env = process.env) {
  const accounts = [
    { username: env.ACCOUNTANT_USERNAME, hash: env.ACCOUNTANT_PASSWORD_HASH, role: 'accountant' },
    { username: env.VIEWER_USERNAME, hash: env.VIEWER_PASSWORD_HASH, role: 'viewer' }
  ];
  const account = accounts.find((item) => item.username && item.hash && item.username === username);
  if (!account || !bcrypt.compareSync(password, account.hash)) return null;
  return { username: account.username, role: account.role };
}
