# AlphaZero ONNX Artifact

- **Pinned path:** `ai/models/alphazero_best.onnx`
- **Repository policy:** ONNX binaries (`*.onnx`, `*.onnx.data`) are generated locally and intentionally not committed.
- **Input tensor name:** `state` (`float32`, shape `[batch, 69]`)
- **Output tensor names:** `policy_logits`, `policy_probs`, `value`

## Export command

```bash
python train_alphazero.py \
  --export-onnx-only \
  --export-onnx-checkpoint checkpoints/best.pt \
  --export-onnx-path ai/models/alphazero_best.onnx
```

This command performs ONNX export and runs an ONNXRuntime numeric parity check against
PyTorch on a verification batch, failing if max absolute error exceeds the configured threshold.

See `ai/models/alphazero_best.preprocess.json` for the canonical preprocessing and normalization
contract that JavaScript inference must follow.
