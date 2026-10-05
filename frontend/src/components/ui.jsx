export function ResultBox({ result, error, warn }) {
  if (error) return <div className="result error">✕ {error}</div>
  if (warn) return <div className="result warn">⚠ {warn}</div>
  if (result) return <div className="result">✓ {result}</div>
  return null
}

export function Field({ label, children }) {
  return (
    <div className="field">
      <label>{label}</label>
      {children}
    </div>
  )
}
