import { useState } from "react";
import { apiClient, ensureCsrf } from "../../api/client";
import { COLOR, FONT_DISPLAY } from "../../theme";
import { Button, inputStyle } from "../../components/ui";

export default function AdminLoginPage({ onLoggedIn }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState(null);

  async function submit(e) {
    e.preventDefault();
    setError(null);
    try {
      await ensureCsrf();
      await apiClient.post("/api/admin/login", { email, password });
      onLoggedIn();
    } catch {
      setError("Invalid email or password.");
    }
  }

  return (
    <div style={{ maxWidth: 360, margin: "80px auto", padding: 24 }}>
      <h1 style={{ fontFamily: FONT_DISPLAY, fontSize: 22, marginBottom: 20 }}>Admin Login</h1>
      <form onSubmit={submit}>
        <div style={{ marginBottom: 12 }}>
          <div style={{ fontSize: 12, color: COLOR.inkSoft, marginBottom: 4 }}>Email</div>
          <input value={email} onChange={(e) => setEmail(e.target.value)} style={inputStyle} type="email" required />
        </div>
        <div style={{ marginBottom: 16 }}>
          <div style={{ fontSize: 12, color: COLOR.inkSoft, marginBottom: 4 }}>Password</div>
          <input value={password} onChange={(e) => setPassword(e.target.value)} style={inputStyle} type="password" required />
        </div>
        {error && <div style={{ color: COLOR.rust, fontSize: 12, marginBottom: 12 }}>{error}</div>}
        <Button variant="gold">Log In</Button>
      </form>
    </div>
  );
}
