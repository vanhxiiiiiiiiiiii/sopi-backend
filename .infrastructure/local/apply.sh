#!/bin/sh

kubectl apply -f mysql
kubectl apply -f api
kubectl apply -f adminer
kubectl apply -f ingress
kubectl apply -f ingress/controller/nginx
