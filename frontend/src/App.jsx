import { Navigate, Route, Routes } from "react-router-dom";
import Shell from "./components/Shell.jsx";
import KioskClockPage from "./pages/KioskClockPage.jsx";
import StaffLoginPage from "./pages/StaffLoginPage.jsx";
import AdminApp from "./pages/admin/AdminApp.jsx";

export default function App() {
  return (
    <Shell>
      <Routes>
        <Route path="/" element={<Navigate to="/kiosk" replace />} />
        <Route path="/kiosk" element={<KioskClockPage />} />
        <Route path="/staff-login" element={<StaffLoginPage />} />
        <Route path="/admin/*" element={<AdminApp />} />
      </Routes>
    </Shell>
  );
}
