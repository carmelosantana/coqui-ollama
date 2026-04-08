#!/bin/bash

# https://github.com/ollama/ollama/issues/2633#issuecomment-1957315877
ollama list | awk 'NR>1 {print $1}' | xargs -I {} sh -c 'echo "Updating model: {}"; ollama pull {}; echo "--"' && echo "All models updated."