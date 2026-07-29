import { Component } from "react";

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { error: null, info: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    this.setState({ info });
    // eslint-disable-next-line no-console
    console.error("Uncaught render error:", error, info);
  }

  render() {
    if (this.state.error) {
      return (
        <div style={{ maxWidth: 820, margin: "48px auto", padding: 24, fontFamily: "monospace" }}>
          <h2 style={{ color: "#C1521F", marginTop: 0 }}>Something went wrong on this screen.</h2>
          <p style={{ fontSize: 13, color: "#7A6A57" }}>
            Please screenshot the details below and send them so it can be fixed, then reload the page.
          </p>
          <pre style={{ background: "#FAF6EC", border: "1px solid #E7DCC6", borderRadius: 8, padding: 14, fontSize: 12, whiteSpace: "pre-wrap", overflowX: "auto" }}>
            {String(this.state.error?.stack || this.state.error?.message || this.state.error)}
            {this.state.info?.componentStack ? "\n\nComponent stack:" + this.state.info.componentStack : ""}
          </pre>
          <button onClick={() => window.location.reload()} style={{ padding: "8px 16px", borderRadius: 7, border: "1px solid #2E2118", background: "#2E2118", color: "#FAF6EC", cursor: "pointer" }}>
            Reload
          </button>
        </div>
      );
    }
    return this.props.children;
  }
}
